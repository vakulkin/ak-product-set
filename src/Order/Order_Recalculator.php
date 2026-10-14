<?php

namespace AK_Set\Order;

use AK_Set\Pricing\Pricing_Engine;
use AK_Set\Pricing\Round_Resolver;
use AK_Set\Models\Set_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Order_Recalculator {

    const BALANCE_SETTLED   = 'settled';
    const BALANCE_UNDERPAID = 'underpaid';
    const BALANCE_OVERPAID  = 'overpaid';
    const BALANCE_UNPAID    = 'unpaid';

    /**
     * Default cost of a manufactured t-shirt in PLN if not customized in options.
     */
    const DEFAULT_TSHIRT_COST = 50.0;

    /**
     * Get configured T-shirt manufacturing cost.
     * Checks optional set-level ACF override first, then global plugin option 'ak_set_tshirt_cost',
     * and finally defaults to DEFAULT_TSHIRT_COST (50.0).
     */
    public static function get_tshirt_cost(?int $set_id = null): float {
        if ($set_id && function_exists('get_field')) {
            $custom = get_field('set_tshirt_cost', $set_id);
            if ($custom !== null && $custom !== '' && is_numeric($custom) && (float)$custom >= 0) {
                return (float)$custom;
            }
        }

        $cost = get_option('ak_set_tshirt_cost', self::DEFAULT_TSHIRT_COST);
        return max(0.0, (float)$cost);
    }

    /**
     * Safely check whether an order is marked as paid.
     */
    public static function is_order_paid(\WC_Order $order): bool {
        try {
            if (method_exists($order, 'is_paid') || ($order instanceof \Mockery\MockInterface)) {
                return (bool) $order->is_paid();
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * Safely retrieve order status string.
     */
    public static function get_order_status(\WC_Order $order): string {
        try {
            if (method_exists($order, 'get_status') || ($order instanceof \Mockery\MockInterface)) {
                return (string) $order->get_status();
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }

    /**
     * Recalculate decomposed order line items based on individual participant-weekend enrollments,
     * apply group pricing for common cohorts and individual pricing for disparate weekends,
     * compute financial balance delta (niedopłata / nadpłata), and generate detailed calculation breakdown.
     *
     * @param array $options [
     *     'reason'               => string,
     *     'retained_tshirt_cost' => float,
     * ]
     * @return array Calculation summary payload
     */
    public static function recalculate(\WC_Order $order, array $options = []): array {
        // Snapshot order state before any changes (use pre-change snapshot if provided by caller)
        $before_snapshot = !empty($options['before_snapshot'])
            ? $options['before_snapshot']
            : self::get_order_state_snapshot($order);

        // 1. Initialize original baseline totals if this is the first modification
        $original_total = $order->get_meta('_ak_original_total');
        if ($original_total === '' || $original_total === false || (float)$original_total <= 0.009) {
            $calc_total = (float) $order->get_total();
            if ($calc_total <= 0.009) {
                foreach ($order->get_items('line_item') as $item) {
                    if ($item instanceof \WC_Order_Item_Product) {
                        $calc_total += (float) $item->get_total();
                    }
                }
            }
            if ($calc_total > 0.009) {
                $original_total = $calc_total;
                $order->update_meta_data('_ak_original_total', $original_total);
            } else {
                $original_total = 0.0;
            }
        } else {
            $original_total = (float) $original_total;
        }

        $total_paid = $order->get_meta('_ak_total_paid');
        if ($total_paid === '' || $total_paid === false) {
            $total_paid = $original_total;
            $order->update_meta_data('_ak_total_paid', $total_paid);
        } else {
            $total_paid = (float) $total_paid;
            if ($total_paid <= 0.009 && $original_total > 0.009) {
                $status = self::get_order_status($order);
                $is_paid = self::is_order_paid($order);
                if ($is_paid || in_array($status, ['processing', 'completed'], true)) {
                    $total_paid = $original_total;
                    $order->update_meta_data('_ak_total_paid', $total_paid);
                }
            }
        }

        $original_headcount = (int) $order->get_meta('_ak_original_headcount');
        if ($original_headcount <= 0) {
            $original_headcount = self::detect_total_headcount($order);
            $order->update_meta_data('_ak_original_headcount', $original_headcount);
        }

        // 2. Resolve parent order timestamp for historical round calculation
        $date_created = $order->get_date_created();
        $order_timestamp = $date_created ? $date_created->getTimestamp() : time();

        // 3. Extract all participants and map their specific attended weekends
        $items = $order->get_items('line_item');
        $participant_enrollments = []; // uuid => ['data' => ..., 'set_id' => ..., 'weekends' => [wid => item_id]]
        $weekend_items_map = [];       // wid => \WC_Order_Item_Product

        foreach ($items as $item_id => $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $set_id = (int) $item->get_meta('_ak_parent_set_id');
            $wid    = (int) $item->get_product_id();

            if ($set_id > 0 && $wid > 0) {
                $weekend_items_map[$wid] = $item;

                $p_list = $item->get_meta('_ak_participants');
                if (is_array($p_list)) {
                    foreach ($p_list as $p) {
                        $uuid = $p['id'] ?? '';
                        if (!$uuid) {
                            continue;
                        }
                        if (!isset($participant_enrollments[$uuid])) {
                            $participant_enrollments[$uuid] = [
                                'data'     => $p,
                                'set_id'   => $set_id,
                                'weekends' => [],
                            ];
                        }
                        $participant_enrollments[$uuid]['weekends'][$wid] = $item_id;
                    }
                }
            }
        }

        $overall_headcount = count($participant_enrollments);

        // 4. Handle Retained T-Shirt Manufacturing Cost (if specified for this cancellation)
        $retained_tshirt = isset($options['retained_tshirt_cost']) ? (float)$options['retained_tshirt_cost'] : 0.0;
        if ($retained_tshirt > 0) {
            self::add_or_update_tshirt_fee($order, $retained_tshirt);
        }

        // 5. Handle cancellation of entire order if headcount is 0
        if ($overall_headcount <= 0) {
            // Remove empty line items
            foreach ($weekend_items_map as $item) {
                $order->remove_item($item->get_id());
            }

            // Remove coupons since no products remain
            try {
                $coupon_items = $order->get_items('coupon');
                if (is_iterable($coupon_items)) {
                    foreach ($coupon_items as $coupon_item) {
                        $order->remove_item($coupon_item->get_id());
                    }
                }
            } catch (\Throwable $e) {}

            // Calculate remaining fees (such as retained T-shirt manufacturing costs)
            $fee_summary = self::get_order_fees_summary($order);
            $fees_total = $fee_summary['total'];
            $fees_section = $fee_summary['section'];

            try {
                $order->calculate_totals(false);
            } catch (\Throwable $e) {}

            // When products are 0, order total in WooCommerce must strictly equal retained fees (or 0.0)
            $new_total = round($fees_total, 2);
            try {
                $order->set_total($new_total);
            } catch (\Throwable $e) {}
            $balance = round($new_total - $total_paid, 2);

            $reason = !empty($options['reason']) ? $options['reason'] : __('Wszyscy uczestnicy zostali anulowani/usunięci z zamówienia.', 'ak-product-set');

            $order->update_status('cancelled', $reason);
            $is_paid = self::is_order_paid($order);

            $balance_info = self::resolve_balance_status($balance, $is_paid, $total_paid);
            $final_status = $balance_info['status'];
            $final_balance = $balance_info['balance'];

            $balance_line = Order_Breakdown_Formatter::resolve_balance_line($final_status, $balance, $total_paid, true);
            $log_status = Order_Breakdown_Formatter::resolve_log_status($final_status, $balance, $total_paid, true);

            $order->update_meta_data('_ak_balance_status', $final_status);
            $order->update_meta_data('_ak_balance_amount', $final_balance);
            $order->update_meta_data('_ak_recalculated_total', $new_total);

            $full_breakdown = Order_Breakdown_Formatter::format_cancellation_breakdown(
                $reason,
                $fees_section,
                $new_total,
                $total_paid,
                $balance_line
            );
            $order->update_meta_data('_ak_pricing_breakdown', $full_breakdown);

            $log_entry = Order_Breakdown_Formatter::format_audit_log_entry(
                $reason,
                $before_snapshot['total'],
                $before_snapshot['summary'],
                $new_total,
                $log_status,
                __('Brak aktywnych uczestników (wszyscy anulowani lub przeniesieni)', 'ak-product-set')
            );
            self::append_audit_log($order, $log_entry);
            $order->save();

            return [
                'status'             => $final_status,
                'recalculated_total' => $new_total,
                'balance_amount'     => $final_balance,
                'total_paid'         => $total_paid,
                'headcount'          => 0,
                'breakdown'          => $full_breakdown,
                'log'                => $log_entry,
            ];
        }

        // 5. Group participants into cohorts with IDENTICAL attended weekend combinations
        // Key: set_id . '|' . sorted_weekends
        $cohorts = [];
        foreach ($participant_enrollments as $uuid => $info) {
            $wids = array_keys($info['weekends']);
            sort($wids);
            $cohort_key = $info['set_id'] . '|' . implode(',', $wids);

            if (!isset($cohorts[$cohort_key])) {
                $cohorts[$cohort_key] = [
                    'set_id'       => $info['set_id'],
                    'weekends'     => $wids,
                    'package_size' => count($wids),
                    'participants' => [],
                ];
            }
            $cohorts[$cohort_key]['participants'][$uuid] = $info['data'];
        }

        // 6. Calculate pricing per cohort and accumulate shares per weekend item
        $weekend_item_totals = []; // wid => float
        $breakdown_sections = [];
        $total_recalculated_base = 0.0;
        $cohort_index = 1;

        foreach ($cohorts as $cohort) {
            $set_id       = $cohort['set_id'];
            $wids         = $cohort['weekends'];
            $package_size = $cohort['package_size'];
            $participants = $cohort['participants'];
            $headcount    = count($participants);

            if ($headcount <= 0) {
                continue;
            }

            $set = new Set_Model($set_id);
            $set_title = $set->get_title() ?: sprintf(__('Zestaw #%d', 'ak-product-set'), $set_id);

            // Resolve round based on parent order date
            $round = Round_Resolver::resolve_package_round($wids, $order_timestamp);
            $tier = Pricing_Engine::resolve_group_tier($headcount);

            // Fetch per-person unit price from active 3D pricing matrix
            $per_person_price = (float) $set->get_price_by_matrix($package_size, $round, $tier);
            $cohort_subtotal = round($per_person_price * $headcount, 2);
            $total_recalculated_base += $cohort_subtotal;

            // Distribute per-person price across this cohort's weekends
            // Each weekend of a participant contributes: per_person_price / package_size
            $weekend_unit_share = round($per_person_price / $package_size, 2);
            $weekend_accumulated_share = 0.0;

            for ($w_i = 0; $w_i < $package_size; $w_i++) {
                $wid = $wids[$w_i];
                if ($w_i === $package_size - 1) {
                    $this_person_share = round($per_person_price - $weekend_accumulated_share, 2);
                } else {
                    $this_person_share = $weekend_unit_share;
                    $weekend_accumulated_share += $this_person_share;
                }

                $this_weekend_cohort_total = round($this_person_share * $headcount, 2);
                $weekend_item_totals[$wid] = ($weekend_item_totals[$wid] ?? 0.0) + $this_weekend_cohort_total;
            }

            // Build breakdown text for this cohort
            $weekend_titles = [];
            foreach ($wids as $wid) {
                $p = wc_get_product($wid);
                $weekend_titles[] = $p ? $p->get_name() : sprintf(__('Termin #%d', 'ak-product-set'), $wid);
            }

            $participant_names = array_map(fn($p) => $p['name'] ?: $p['id'], $participants);

            $breakdown_sections[] = Order_Breakdown_Formatter::format_cohort_section(
                $cohort_index++,
                $set_title,
                $participant_names,
                $package_size,
                $weekend_titles,
                $round,
                $tier,
                $per_person_price,
                $headcount,
                $cohort_subtotal
            );
        }

        // 7. Update line items in order
        foreach ($weekend_items_map as $wid => $item) {
            $item_participants = $item->get_meta('_ak_participants');
            $item_headcount = is_array($item_participants) ? count($item_participants) : 0;

            if ($item_headcount <= 0 || !isset($weekend_item_totals[$wid])) {
                // No participants in this weekend: remove line item completely
                $order->remove_item($item->get_id());
                continue;
            }

            $item_total = $weekend_item_totals[$wid];
            $item->set_quantity(max(1, $item_headcount));
            $item->set_subtotal($item_total);
            $item->set_total($item_total);
            $item->update_meta_data('_ak_headcount', $item_headcount);
        }

        // 8. Handle Coupons (Percentage vs Fixed proportional distribution)
        self::apply_coupons_recalculation($order, $original_headcount, $overall_headcount);

        // 9. Calculate final order totals via native WooCommerce
        $order->calculate_totals(false);

        $new_total = (float) $order->get_total();
        $balance = round($new_total - $total_paid, 2);

        $is_paid = self::is_order_paid($order);
        $balance_info = self::resolve_balance_status($balance, $is_paid, $total_paid);
        $balance_status = $balance_info['status'];
        $balance = $balance_info['balance'];

        $order->update_meta_data('_ak_recalculated_total', $new_total);
        $order->update_meta_data('_ak_balance_amount', $balance);
        $order->update_meta_data('_ak_balance_status', $balance_status);

        // Fee section in breakdown if fees exist (e.g. retained T-shirt costs)
        $fee_summary = self::get_order_fees_summary($order);
        $fees_section = $fee_summary['section'];

        // 10. Compile full comprehensive pricing breakdown
        $base_date_formatted = $date_created ? $date_created->date('Y-m-d H:i') : current_time('Y-m-d H:i');
        $balance_formatted = Order_Breakdown_Formatter::resolve_balance_line($balance_status, $balance, $total_paid, false);

        $full_breakdown = Order_Breakdown_Formatter::format_recalculation_breakdown(
            $base_date_formatted,
            (int) $order->get_id(),
            $breakdown_sections,
            $total_recalculated_base,
            $fees_section,
            $new_total,
            $total_paid,
            $balance_formatted
        );

        $order->update_meta_data('_ak_pricing_breakdown', $full_breakdown);

        // 12. Generate timestamped audit trail log with Before and After snapshots
        $reason = !empty($options['reason']) ? $options['reason'] : __('Modyfikacja terminów / przeliczenie', 'ak-product-set');
        $balance_text = Order_Breakdown_Formatter::resolve_log_status($balance_status, $balance, $total_paid, false);

        $after_snapshot = self::get_order_state_snapshot($order);
        $log_entry = Order_Breakdown_Formatter::format_audit_log_entry(
            $reason,
            $before_snapshot['total'],
            $before_snapshot['summary'],
            $new_total,
            $balance_text,
            $after_snapshot['summary']
        );

        self::append_audit_log($order, $log_entry);
        $order->save();

        return [
            'status'             => $balance_status,
            'recalculated_total' => $new_total,
            'balance_amount'     => $balance,
            'total_paid'         => $total_paid,
            'headcount'          => $overall_headcount,
            'breakdown'          => $full_breakdown,
            'log'                => $log_entry,
        ];
    }

    /**
     * Mark an order balance as manually settled (clears underpayment or confirms refund).
     */
    public static function mark_settled(\WC_Order $order, string $note = ''): void {
        $current_total = (float) $order->get_total();
        $previous_paid = $order->get_meta('_ak_total_paid');
        if ($previous_paid !== '' && $previous_paid !== false) {
            $order->update_meta_data('_ak_pre_settled_total_paid', (float) $previous_paid);
        }
        $order->update_meta_data('_ak_manually_settled', '1');
        $order->update_meta_data('_ak_total_paid', $current_total);
        $order->update_meta_data('_ak_balance_amount', 0.0);
        $order->update_meta_data('_ak_balance_status', self::BALANCE_SETTLED);

        // Record manual initial payment info if not recorded yet
        if ($order->get_meta('_ak_initial_payment_recorded') !== 'yes') {
            $order->update_meta_data('_ak_initial_payment_recorded', 'yes');
            $order->update_meta_data('_ak_initial_payment_method', __('Płatność uregulowana ręcznie przez administratora', 'ak-product-set'));
            $order->update_meta_data('_ak_initial_payment_method_id', 'manual_admin');
            $order->update_meta_data('_ak_initial_payment_amount', $current_total);
            $order->update_meta_data('_ak_initial_payment_date', current_time('Y-m-d H:i'));
            $order->update_meta_data('_ak_initial_payment_txn_id', '');
        }

        // Update pricing breakdown text to reflect settled state
        $breakdown = (string) $order->get_meta('_ak_pricing_breakdown');
        if ($breakdown !== '') {
            $pattern = '/(Pierwotnie wpłacono:.*?\n)(BILANS:.*?)(\n=|$)/s';
            $replacement = sprintf(
                "Uregulowano: %s zł\nBILANS: Uregulowane (brak zaległości)$3",
                number_format_i18n($current_total, 2)
            );
            $updated_breakdown = preg_replace($pattern, $replacement, $breakdown);
            if ($updated_breakdown) {
                $order->update_meta_data('_ak_pricing_breakdown', $updated_breakdown);
            }
        }

        $custom_note = $note !== '' ? ': ' . $note : '';
        $log_entry = sprintf(
            '[%s] %s%s',
            current_time('Y-m-d H:i'),
            __('Rozliczenie oznaczone ręcznie jako uregulowane', 'ak-product-set'),
            $custom_note
        );

        self::append_audit_log($order, $log_entry);
        $order->save();
    }

    /**
     * Revert a manually settled order back to its calculated balance (unsettled state).
     *
     * @return array Recalculation result
     */
    public static function mark_unsettled(\WC_Order $order, string $note = ''): array {
        $pre_settled = $order->get_meta('_ak_pre_settled_total_paid');
        if ($pre_settled !== '' && $pre_settled !== false) {
            $restore_paid = (float) $pre_settled;
        } else {
            $original = $order->get_meta('_ak_original_total');
            $restore_paid = ($original !== '' && $original !== false) ? (float) $original : 0.0;
        }

        $order->update_meta_data('_ak_total_paid', $restore_paid);
        $order->delete_meta_data('_ak_manually_settled');
        $order->delete_meta_data('_ak_pre_settled_total_paid');

        $reason = __('Cofnięcie oznaczenia jako uregulowane (przywrócenie salda)', 'ak-product-set');
        if ($note !== '') {
            $reason .= ': ' . $note;
        }

        return self::recalculate($order, [
            'reason' => $reason,
        ]);
    }

    /**
     * Helper to detect total unique headcount across all AK items in an order.
     */
    public static function detect_total_headcount(\WC_Order $order): int {
        $uuids = [];
        foreach ($order->get_items('line_item') as $item) {
            $participants = $item->get_meta('_ak_participants');
            if (is_array($participants)) {
                foreach ($participants as $p) {
                    if (!empty($p['id'])) {
                        $uuids[$p['id']] = true;
                    }
                }
            }
        }
        return count($uuids);
    }

    /**
     * Record initial pricing breakdown and financial balance on a clean, newly decomposed order.
     */
    public static function record_initial_order_state(\WC_Order $order): void {
        $total = (float) $order->get_total();
        if ($total <= 0.009) {
            foreach ($order->get_items('line_item') as $item) {
                if ($item instanceof \WC_Order_Item_Product) {
                    $total += (float) $item->get_total();
                }
            }
        }
        $order->update_meta_data('_ak_original_total', $total);

        $is_paid = self::is_order_paid($order);

        if ($is_paid || $total <= 0.009) {
            $order->update_meta_data('_ak_total_paid', $total);
            $order->update_meta_data('_ak_balance_amount', 0.0);
            $order->update_meta_data('_ak_balance_status', self::BALANCE_SETTLED);

            $method_title = '';
            try {
                if (is_callable([$order, 'get_payment_method_title'])) {
                    $method_title = (string) $order->get_payment_method_title();
                }
            } catch (\Throwable $e) {}

            $method_id = '';
            try {
                if (is_callable([$order, 'get_payment_method'])) {
                    $method_id = (string) $order->get_payment_method();
                }
            } catch (\Throwable $e) {}

            $date_paid = '';
            try {
                if (is_callable([$order, 'get_date_paid']) && $order->get_date_paid()) {
                    $date_paid = $order->get_date_paid()->date('Y-m-d H:i');
                }
            } catch (\Throwable $e) {}
            if (!$date_paid) {
                $date_paid = current_time('Y-m-d H:i');
            }

            $txn_id = '';
            try {
                if (is_callable([$order, 'get_transaction_id'])) {
                    $txn_id = (string) $order->get_transaction_id();
                }
            } catch (\Throwable $e) {}

            $order->update_meta_data('_ak_initial_payment_recorded', 'yes');
            $order->update_meta_data('_ak_initial_payment_method', $method_title ?: ($method_id ?: __('Bramka płatności', 'ak-product-set')));
            $order->update_meta_data('_ak_initial_payment_method_id', $method_id);
            $order->update_meta_data('_ak_initial_payment_amount', $total);
            $order->update_meta_data('_ak_initial_payment_date', $date_paid);
            $order->update_meta_data('_ak_initial_payment_txn_id', $txn_id);
        } else {
            $order->update_meta_data('_ak_total_paid', 0.0);
            $order->update_meta_data('_ak_balance_amount', $total);
            $order->update_meta_data('_ak_balance_status', self::BALANCE_UNPAID);
        }

        $breakdown_sections = [];
        $cohort_index = 1;
        $participant_enrollments = [];

        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $set_id = (int) $item->get_meta('_ak_parent_set_id');
                if (!$set_id) {
                    continue;
                }
                $wid = (int) $item->get_product_id();
                $p_list = $item->get_meta('_ak_participants');
                if (is_array($p_list)) {
                    foreach ($p_list as $p) {
                        $uuid = $p['id'] ?? '';
                        if (!$uuid) {
                            continue;
                        }
                        if (!isset($participant_enrollments[$uuid])) {
                            $participant_enrollments[$uuid] = [
                                'data'     => $p,
                                'set_id'   => $set_id,
                                'weekends' => [],
                            ];
                        }
                        $participant_enrollments[$uuid]['weekends'][$wid] = $item->get_name();
                    }
                }
            }
        }

        $cohorts = [];
        foreach ($participant_enrollments as $uuid => $info) {
            $wids = array_keys($info['weekends']);
            sort($wids);
            $cohort_key = $info['set_id'] . '|' . implode(',', $wids);
            if (!isset($cohorts[$cohort_key])) {
                $cohorts[$cohort_key] = [
                    'set_id'        => $info['set_id'],
                    'weekends'      => $wids,
                    'weekend_names' => array_values($info['weekends']),
                    'package_size'  => count($wids),
                    'participants'  => [],
                ];
            }
            $cohorts[$cohort_key]['participants'][$uuid] = $info['data'];
        }

        $date_created = $order->get_date_created();
        $order_timestamp = $date_created ? $date_created->getTimestamp() : current_time('timestamp');

        foreach ($cohorts as $cohort) {
            $set_id       = $cohort['set_id'];
            $wids         = $cohort['weekends'];
            $package_size = $cohort['package_size'];
            $participants = $cohort['participants'];
            $headcount    = count($participants);

            $set = new Set_Model($set_id);
            $set_title = $set->get_title() ?: sprintf(__('Zestaw #%d', 'ak-product-set'), $set_id);

            $round = Round_Resolver::resolve_package_round($wids, $order_timestamp);
            $tier = Pricing_Engine::resolve_group_tier($headcount);
            $per_person_price = (float) $set->get_price_by_matrix($package_size, $round, $tier);
            $cohort_subtotal = round($per_person_price * $headcount, 2);

            $participant_names = array_map(fn($p) => $p['name'] ?: $p['id'], $participants);

            $breakdown_sections[] = Order_Breakdown_Formatter::format_cohort_section(
                $cohort_index++,
                $set_title,
                $participant_names,
                $package_size,
                $cohort['weekend_names'],
                $round,
                $tier,
                $per_person_price,
                $headcount,
                $cohort_subtotal
            );
        }

        $date_formatted = $date_created ? $date_created->date('Y-m-d H:i') : current_time('Y-m-d H:i');
        $is_order_settled = ($order->get_meta('_ak_balance_status') === self::BALANCE_SETTLED);
        $full_breakdown = Order_Breakdown_Formatter::format_initial_order_breakdown(
            $date_formatted,
            (int) $order->get_id(),
            $breakdown_sections,
            $total,
            $is_order_settled
        );

        $order->update_meta_data('_ak_pricing_breakdown', $full_breakdown);
        $order->save();
    }


    /**
     * Get snapshot summary of current participants and attended weekends in an order.
     */
    public static function get_order_state_snapshot(\WC_Order $order): array {
        $participants_map = [];
        $items = [];
        try {
            $items = $order->get_items('line_item');
        } catch (\Throwable $e) {
            $items = [];
        }

        if (is_iterable($items)) {
            foreach ($items as $item) {
                if (!($item instanceof \WC_Order_Item_Product)) {
                    continue;
                }
                $wtitle = '';
                try {
                    $wtitle = (string) $item->get_name();
                } catch (\Throwable $e) {
                    try {
                        $wtitle = (string) $item->get_product_id();
                    } catch (\Throwable $t) {
                        $wtitle = '';
                    }
                }

                $p_list = [];
                try {
                    $p_list = $item->get_meta('_ak_participants');
                } catch (\Throwable $e) {
                    $p_list = [];
                }

                if (is_array($p_list)) {
                    foreach ($p_list as $p) {
                        $uuid = $p['id'] ?? '';
                        if (!$uuid) {
                            continue;
                        }
                        if (!isset($participants_map[$uuid])) {
                            $participants_map[$uuid] = [
                                'name'     => $p['name'] ?? $uuid,
                                'weekends' => [],
                            ];
                        }
                        $participants_map[$uuid]['weekends'][] = $wtitle ?: (string) ($p['id'] ?? '');
                    }
                }
            }
        }

        $summary = Order_Breakdown_Formatter::format_order_state_summary($participants_map);

        $total = 0.0;
        try {
            $total = (float) $order->get_total();
        } catch (\Throwable $e) {
            $total = 0.0;
        }

        return [
            'total'        => $total,
            'participants' => $participants_map,
            'summary'      => $summary,
        ];
    }

    /**
     * Append an entry to the concatenated order meta history and order notes.
     */
    public static function append_audit_log(\WC_Order $order, string $entry): void {
        $raw_history = $order->get_meta('_ak_balance_history');
        if (is_array($raw_history)) {
            $history = implode("\n", array_filter($raw_history, 'is_string'));
        } else {
            $history = (string) $raw_history;
        }
        $updated_history = $history !== '' ? $history . "\n" . $entry : $entry;
        $order->update_meta_data('_ak_balance_history', $updated_history);
        $order->add_order_note($entry);
    }

    /**
     * Reapply coupons with proportional allocation for fixed discounts or uniform percentage.
     */
    private static function apply_coupons_recalculation(\WC_Order $order, int $orig_headcount, int $new_headcount): void {
        $coupon_items = [];
        try {
            $coupon_items = $order->get_items('coupon');
        } catch (\Throwable $e) {}

        if (empty($coupon_items) || !is_iterable($coupon_items)) {
            return;
        }

        $headcount_ratio = ($orig_headcount > 0 && $new_headcount > 0)
            ? min(1.0, $new_headcount / $orig_headcount)
            : 1.0;

        foreach ($coupon_items as $coupon_item) {
            $original_discount = (float) $coupon_item->get_meta('_ak_original_discount_amount');
            if ($original_discount <= 0) {
                $original_discount = (float) $coupon_item->get_discount();
                $coupon_item->update_meta_data('_ak_original_discount_amount', $original_discount);
            }

            // Proportionally adjust fixed-amount coupon discount by headcount ratio
            $adjusted_discount = round($original_discount * $headcount_ratio, 2);
            $coupon_item->set_discount($adjusted_discount);
        }
    }

    /**
     * Add or update a retained t-shirt fee line item on the order.
     */
    private static function add_or_update_tshirt_fee(\WC_Order $order, float $cost): void {
        $fee_item = null;
        try {
            $fees = $order->get_items('fee');
            if (is_iterable($fees)) {
                foreach ($fees as $fee) {
                    if ($fee->get_name() === __('Koszt wyprodukowanej koszulki', 'ak-product-set') || $fee->get_meta('_ak_is_tshirt_fee')) {
                        $fee_item = $fee;
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {}

        if (!$fee_item) {
            $fee_item = new \WC_Order_Item_Fee();
            $fee_item->set_name(__('Koszt wyprodukowanej koszulki', 'ak-product-set'));
            $fee_item->add_meta_data('_ak_is_tshirt_fee', '1', true);
            $order->add_item($fee_item);
        }

        $existing_amount = (float) $fee_item->get_total();
        $new_amount = round($existing_amount + $cost, 2);
        if (is_callable([$fee_item, 'set_amount'])) {
            try {
                $fee_item->set_amount($new_amount);
            } catch (\Throwable $e) {}
        }
        $fee_item->set_total($new_amount);
    }

    /**
     * Compute total of existing fees and format lines for pricing breakdown.
     *
     * @return array{total: float, lines: string[], section: string}
     */
    private static function get_order_fees_summary(\WC_Order $order): array {
        $fees_total = 0.0;
        $fee_lines = [];
        try {
            $fee_items = $order->get_items('fee');
            if (is_iterable($fee_items)) {
                foreach ($fee_items as $fee) {
                    $fee_val = (float) $fee->get_total();
                    if ($fee_val > 0.009) {
                        $fees_total += $fee_val;
                        $fee_name = is_callable([$fee, 'get_name']) ? $fee->get_name() : __('Opłata', 'ak-product-set');
                        $fee_lines[] = sprintf('• %s: %s zł', $fee_name, number_format_i18n($fee_val, 2));
                    }
                }
            }
        } catch (\Throwable $e) {}

        $fees_section = !empty($fee_lines)
            ? sprintf("--------------------------------------------------\n%s:\n%s\n", __('Naliczone opłaty (w tym potrącenia koszulek)', 'ak-product-set'), implode("\n", $fee_lines))
            : '';

        return [
            'total'   => $fees_total,
            'lines'   => $fee_lines,
            'section' => $fees_section,
        ];
    }

    /**
     * Determines balance status and normalized balance based on balance amount, is_paid flag, and total paid.
     *
     * @return array{status: string, balance: float}
     */
    private static function resolve_balance_status(float $balance, bool $is_paid, float $total_paid): array {
        if ($balance > 0.009) {
            $status = (!$is_paid && $total_paid <= 0.009)
                ? self::BALANCE_UNPAID
                : self::BALANCE_UNDERPAID;
            return ['status' => $status, 'balance' => $balance];
        }

        if ($balance < -0.009) {
            return ['status' => self::BALANCE_OVERPAID, 'balance' => $balance];
        }

        return ['status' => self::BALANCE_SETTLED, 'balance' => 0.0];
    }
}

