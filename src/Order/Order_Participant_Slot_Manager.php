<?php

namespace AK_Set\Order;

use AK_Set\Support\Helper;
use AK_Set\Models\Participant_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Order_Participant_Slot_Manager {

    /**
     * Remove a specific single weekend enrollment for a participant.
     */
    public static function remove_weekend_for_participant(\WC_Order $order, string $participant_uuid, int $weekend_id, bool $tshirt_retained = false, bool $recalculate = true): array {
        $before_snapshot = null;
        $participant_name = '';
        $found = false;
        $before_snapshot = $recalculate ? Order_Recalculator::get_order_state_snapshot($order) : null;
        $tshirt_cost = 0.0;
        if ($tshirt_retained) {
            $set_id = (int) $order->get_meta('_ak_parent_set_id');
            $tshirt_cost = Order_Recalculator::get_tshirt_cost($set_id);
        }

        $item = self::find_weekend_item($order, $weekend_id);
        if ($item) {
            $participants = $item->get_meta('_ak_participants');
            if (is_array($participants)) {
                $was_active = false;
                foreach ($participants as &$p) {
                    if (!empty($p['id']) && $p['id'] === $participant_uuid) {
                        $found = true;
                        if ($participant_name === '' && !empty($p['name'])) {
                            $participant_name = (string) $p['name'];
                        }
                        if (($p['status'] ?? 'active') === 'active') {
                            $was_active = true;
                        }
                        $p['status'] = 'cancelled';
                        $p['cancelled_at'] = current_time('mysql');
                        if ($tshirt_retained) {
                            $p['tshirt_retained_count'] = ((int) ($p['tshirt_retained_count'] ?? 0)) + 1;
                            Order_Recalculator::add_item_produced_tshirt($item, [
                                'participant_uuid' => $participant_uuid,
                                'participant_name' => $participant_name,
                                'cost'             => $tshirt_cost,
                                'size'             => $p['tshirt_size'] ?? '',
                                'cut'              => $p['tshirt_cut'] ?? '',
                                'reason'           => 'cancellation',
                            ]);
                        }
                    }
                }
                unset($p);

                if ($found) {
                    self::save_item_participants($item, $participants);
                    if ($was_active && function_exists('wc_update_product_stock')) {
                        wc_update_product_stock($weekend_id, 1, 'increase');
                    }
                }
            }
        }

        if (!$found) {
            return [
                'success' => false,
                'error'   => __('Uczestnik nie został znaleziony w wybranym terminie.', 'ak-product-set'),
            ];
        }

        if (!$recalculate) {
            return [
                'success'          => true,
                'participant_name' => $participant_name,
            ];
        }

        $w_prod = wc_get_product($weekend_id);
        $w_title = $w_prod ? $w_prod->get_name() : (string) $weekend_id;

        $tshirt_cost = $tshirt_retained ? Order_Recalculator::get_tshirt_cost() : 0.0;
        $reason = sprintf(
            /* translators: 1: participant name, 2: weekend title */
            __('Anulowanie terminu "%2$s" dla uczestnika: %1$s', 'ak-product-set'),
            $participant_name ?: $participant_uuid,
            $w_title
        );

        if ($tshirt_retained && $tshirt_cost > 0) {
            $reason .= sprintf(' (potrącono koszt koszulki: %s zł)', number_format_i18n($tshirt_cost, 2));
        }

        $recalc_result = Order_Recalculator::recalculate($order, [
            'reason'          => $reason,
            'before_snapshot' => $before_snapshot,
        ]);

        return [
            'success'          => true,
            'participant_name' => $participant_name,
            'recalculation'    => $recalc_result,
        ];
    }

    /**
     * Mark a participant weekend enrollment as transferred to another order.
     */
    public static function mark_weekend_transferred(\WC_Order $order, string $participant_uuid, int $weekend_id, int $target_order_id): bool {
        $found = false;
        $item = self::find_weekend_item($order, $weekend_id);
        if ($item) {
            $participants = $item->get_meta('_ak_participants');
            if (is_array($participants)) {
                foreach ($participants as &$p) {
                    if (!empty($p['id']) && $p['id'] === $participant_uuid && ($p['status'] ?? 'active') === 'active') {
                        $p['status'] = 'transferred';
                        $p['transferred_to_order'] = $target_order_id;
                        $p['transferred_at'] = current_time('mysql');
                        $found = true;
                    }
                }
                unset($p);

                if ($found) {
                    self::save_item_participants($item, $participants);
                    if (function_exists('wc_update_product_stock')) {
                        wc_update_product_stock($weekend_id, 1, 'increase');
                    }
                }
            }
        }
        return $found;
    }

    /**
     * Restore a cancelled or transferred weekend for a participant back to active.
     */
    public static function restore_weekend_for_participant(\WC_Order $order, string $participant_uuid, int $weekend_id): array {
        $found = false;
        $participant_name = '';
        $before_snapshot = Order_Recalculator::get_order_state_snapshot($order);

        $item = self::find_weekend_item($order, $weekend_id);
        if ($item) {
            $participants = $item->get_meta('_ak_participants');
            if (is_array($participants)) {
                foreach ($participants as &$p) {
                    if (!empty($p['id']) && $p['id'] === $participant_uuid && ($p['status'] ?? 'active') !== 'active') {
                        $found = true;
                        $participant_name = (string) ($p['name'] ?? $participant_uuid);
                        $p['status'] = 'active';
                        unset($p['cancelled_at'], $p['transferred_to_order'], $p['transferred_at']);
                        break;
                    }
                }
                unset($p);

                if ($found) {
                    self::save_item_participants($item, $participants);
                    if (function_exists('wc_update_product_stock')) {
                        try {
                            wc_update_product_stock($weekend_id, 1, 'decrease');
                        } catch (\Throwable $e) {}
                    }
                }
            }
        }

        if (!$found) {
            return [
                'success' => false,
                'error'   => __('Uczestnik / termin nie został odnaleziony w tym zamówieniu.', 'ak-product-set'),
            ];
        }

        $current_status = Order_Recalculator::get_order_status($order);
        if (in_array($current_status, ['cancelled', 'wc-cancelled', 'trash'], true)) {
            $target_status = (string) $order->get_meta('_ak_pre_cancelled_status');
            if (!$target_status || in_array($target_status, ['cancelled', 'wc-cancelled', 'trash'], true)) {
                $is_paid_order = Order_Recalculator::is_order_paid($order) || ((float) $order->get_meta('_ak_total_paid') > 0.009);
                $target_status = $is_paid_order ? 'processing' : 'pending';
            }
            Order_Payment_Tracker::$is_internal_transition = true;
            try {
                $order->update_status($target_status, sprintf(
                    /* translators: %s: participant name */
                    __('Przywrócono zamówienie po przywróceniu terminu dla %s', 'ak-product-set'),
                    $participant_name
                ));
            } finally {
                Order_Payment_Tracker::$is_internal_transition = false;
            }
        }

        $w_prod = wc_get_product($weekend_id);
        $w_title = $w_prod ? $w_prod->get_name() : (string) $weekend_id;

        $reason = sprintf(
            /* translators: 1: participant name, 2: weekend title */
            __('Przywrócenie terminu "%2$s" dla uczestnika: %1$s', 'ak-product-set'),
            $participant_name,
            $w_title
        );

        $recalc_result = Order_Recalculator::recalculate($order, [
            'reason'          => $reason,
            'before_snapshot' => $before_snapshot,
        ]);

        return [
            'success'          => true,
            'participant_name' => $participant_name,
            'recalculation'    => $recalc_result,
        ];
    }

    /**
     * Revert / cancel a single 60 zł (or configured unit cost) t-shirt fee on the order.
     */
    public static function revert_tshirt_fee(\WC_Order $order, ?string $participant_uuid = null, ?int $weekend_id = null): array {
        $set_id = (int) $order->get_meta('_ak_parent_set_id');
        $cost = Order_Recalculator::get_tshirt_cost($set_id);
        $existing_fee = Order_Recalculator::get_order_tshirt_fee_total($order);

        if ($existing_fee <= 0.009) {
            return [
                'success' => false,
                'error'   => __('Brak naliczonej opłaty za koszulki w tym zamówieniu.', 'ak-product-set'),
            ];
        }

        $before_snapshot = Order_Recalculator::get_order_state_snapshot($order);
        $deduct = min($cost, $existing_fee);

        // Decrement / remove produced t-shirt record
        $removed_record = null;
        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }
            if ($weekend_id && (int) $item->get_product_id() !== $weekend_id) {
                continue;
            }
            $removed_record = Order_Recalculator::remove_item_produced_tshirt($item, $participant_uuid ?: '');
            if ($removed_record !== null) {
                break;
            }
        }

        $deduct = ($removed_record !== null && isset($removed_record['cost']))
            ? (float) $removed_record['cost']
            : min($cost, $existing_fee);

        $reason = sprintf(
            /* translators: %s: formatted cost */
            __('Cofnięto opłatę za wyprodukowaną koszulkę (-%s zł)', 'ak-product-set'),
            number_format_i18n($deduct, 2)
        );

        $recalc_result = Order_Recalculator::recalculate($order, [
            'reason'          => $reason,
            'before_snapshot' => $before_snapshot,
        ]);

        return [
            'success'       => true,
            'recalculation' => $recalc_result,
        ];
    }

    /**
     * Delete a specific manufactured t-shirt by its UUID and recalculate the order.
     */
    public static function delete_produced_tshirt(\WC_Order $order, string $tshirt_uuid, ?int $item_id = null): array {
        try {
            if (is_callable([$order, 'get_status']) && $order->get_status() === 'cancelled') {
                return [
                    'success' => false,
                    'error'   => __('Nie można modyfikować koszulek w anulowanym zamówieniu.', 'ak-product-set'),
                ];
            }
        } catch (\Throwable $e) {}

        $before_snapshot = Order_Recalculator::get_order_state_snapshot($order);
        $deleted_record = null;
        $items = $order->get_items('line_item');

        foreach ($items as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }
            if ($item_id && (int) $item->get_id() !== $item_id) {
                continue;
            }

            $deleted_record = Order_Recalculator::delete_item_produced_tshirt_by_uuid($item, $tshirt_uuid);
            if ($deleted_record !== null) {
                break;
            }
        }

        // If item_id was specified but not found, retry across all line items
        if ($deleted_record === null && $item_id) {
            foreach ($items as $item) {
                if (!($item instanceof \WC_Order_Item_Product)) {
                    continue;
                }
                $deleted_record = Order_Recalculator::delete_item_produced_tshirt_by_uuid($item, $tshirt_uuid);
                if ($deleted_record !== null) {
                    break;
                }
            }
        }

        if ($deleted_record === null) {
            return [
                'success' => false,
                'error'   => __('Nie znaleziono wyprodukowanej koszulki o podanym identyfikatorze.', 'ak-product-set'),
            ];
        }

        $cost = (float) ($deleted_record['cost'] ?? 0.0);
        $size_cut = trim(($deleted_record['size'] ?? '') . ' ' . Helper::get_tshirt_cut_label($deleted_record['cut'] ?? ''));
        $name = (string) ($deleted_record['participant_name'] ?? '');

        $reason = sprintf(
            /* translators: 1: size/cut, 2: participant name, 3: cost */
            __('Anulowano opłatę za koszulkę %1$s dla: %2$s (-%3$s zł)', 'ak-product-set'),
            $size_cut ?: __('(brak rozmiaru)', 'ak-product-set'),
            $name ?: __('Uczestnik', 'ak-product-set'),
            number_format_i18n($cost, 2)
        );

        $recalc_result = Order_Recalculator::recalculate($order, [
            'reason'          => $reason,
            'before_snapshot' => $before_snapshot,
        ]);

        return [
            'success'        => true,
            'deleted_tshirt' => $deleted_record,
            'recalculation'  => $recalc_result,
        ];
    }



    /**
     * Add a participant with selectable weekend enrollment.
     *
     * @param array $selected_weekend_ids Empty array adds to all weekends of the set in order
     */
    public static function add_participant(\WC_Order $order, int $set_id, array $participant_data, array $selected_weekend_ids = []): array {
        $p_model = new Participant_Model($participant_data);
        $p_record = $p_model->to_array();

        $selected_weekend_ids = array_map('intval', array_filter($selected_weekend_ids));
        $updated_items = 0;

        $existing_items_by_wid = [];
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product && (int)$item->get_meta('_ak_parent_set_id') === $set_id) {
                $existing_items_by_wid[(int)$item->get_product_id()] = $item;
            }
        }

        // If no specific weekends selected, default to all existing weekends of this set in order
        $target_wids = !empty($selected_weekend_ids) ? $selected_weekend_ids : array_keys($existing_items_by_wid);

        if (empty($target_wids)) {
            return [
                'success' => false,
                'error'   => __('Nie wybrano żadnego terminu dla nowego uczestnika.', 'ak-product-set'),
            ];
        }

        $before_snapshot = Order_Recalculator::get_order_state_snapshot($order);

        foreach ($target_wids as $wid) {
            $attached = self::attach_participant_to_order($order, $wid, $set_id, $p_record);
            if (!$attached) {
                continue;
            }

            if (function_exists('wc_update_product_stock')) {
                wc_update_product_stock($wid, 1, 'decrease');
            }

            $updated_items++;
        }

        if ($updated_items === 0) {
            return [
                'success' => false,
                'error'   => __('Nie udało się przypisać uczestnika do wybranych terminów.', 'ak-product-set'),
            ];
        }

        $order->update_meta_data('_ak_parent_set_id', $set_id);
        $order->update_meta_data('_ak_order_decomposed', '1');

        $current_status = Order_Recalculator::get_order_status($order);
        if (in_array($current_status, ['cancelled', 'wc-cancelled', 'trash'], true)) {
            $target_status = (string) $order->get_meta('_ak_pre_cancelled_status');
            if (!$target_status || in_array($target_status, ['cancelled', 'wc-cancelled', 'trash'], true)) {
                $is_paid_order = Order_Recalculator::is_order_paid($order) || ((float) $order->get_meta('_ak_total_paid') > 0.009);
                $target_status = $is_paid_order ? 'processing' : 'pending';
            }
            Order_Payment_Tracker::$is_internal_transition = true;
            try {
                $order->update_status($target_status, sprintf(
                    /* translators: %s: participant name */
                    __('Przywrócono zamówienie po dodaniu uczestnika %s', 'ak-product-set'),
                    $p_model->name ?: $p_model->id
                ));
            } finally {
                Order_Payment_Tracker::$is_internal_transition = false;
            }
        }

        $reason = sprintf(
            /* translators: 1: name, 2: count */
            __('Dodanie uczestnika: %1$s (wybrane terminy: %2$d)', 'ak-product-set'),
            $p_model->name ?: $p_model->id,
            $updated_items
        );

        $recalc_result = Order_Recalculator::recalculate($order, [
            'reason'          => $reason,
            'before_snapshot' => $before_snapshot,
        ]);

        return [
            'success'          => true,
            'participant_id'   => $p_model->id,
            'participant_name' => $p_model->name,
            'recalculation'    => $recalc_result,
        ];
    }

    /**
     * Reschedule a specific weekend for a participant (Weekend A -> Weekend B).
     */
    public static function reschedule_weekend_for_participant(\WC_Order $order, string $participant_uuid, int $from_weekend_id, int $to_weekend_id): array {
        if ($from_weekend_id === $to_weekend_id) {
            return [
                'success' => false,
                'error'   => __('Nowy termin musi różnić się od dotychczasowego.', 'ak-product-set'),
            ];
        }

        $participant_data = null;
        $set_id = 0;
        $from_item = null;
        $to_item = null;

        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $pid = (int) $item->get_product_id();

            if ($pid === $from_weekend_id) {
                $from_item = $item;
                $participants = $item->get_meta('_ak_participants');
                if (is_array($participants)) {
                    foreach ($participants as $p) {
                        if (!empty($p['id']) && $p['id'] === $participant_uuid && ($p['status'] ?? 'active') === 'active') {
                            $participant_data = $p;
                            $set_id = (int) $item->get_meta('_ak_parent_set_id');
                            break;
                        }
                    }
                }
            } elseif ($pid === $to_weekend_id) {
                $to_item = $item;
            }
        }

        if (!$participant_data || !$from_item) {
            return [
                'success' => false,
                'error'   => __('Uczestnik nie został znaleziony w wyjściowym terminie.', 'ak-product-set'),
            ];
        }

        // Prevent moving to a weekend the participant is already enrolled in
        if ($to_item) {
            $to_participants = (array) $to_item->get_meta('_ak_participants');
            foreach ($to_participants as $p) {
                if (!empty($p['id']) && $p['id'] === $participant_uuid && ($p['status'] ?? 'active') === 'active') {
                    $p_name = $participant_data['name'] ?? $participant_uuid;
                    return [
                        'success' => false,
                        'error'   => sprintf(
                            __('Uczestnik "%s" jest już zapisany na wybrany termin docelowy w tym zamówieniu. Wybierz inny termin.', 'ak-product-set'),
                            $p_name
                        ),
                    ];
                }
            }
        }

        // 1. Capture snapshot before any modification to source or destination items
        $before_snapshot = Order_Recalculator::get_order_state_snapshot($order);

        $from_participants = (array) $from_item->get_meta('_ak_participants');
        $from_remaining = array_values(array_filter($from_participants, fn($p) => ($p['id'] ?? '') !== $participant_uuid));
        self::save_item_participants($from_item, $from_remaining);

        if (function_exists('wc_update_product_stock')) {
            wc_update_product_stock($from_weekend_id, 1, 'increase');
        }

        // If source item now has 0 participants, delete it
        if (empty($from_remaining)) {
            $order->remove_item($from_item->get_id());
        }

        // 3. Add to destination weekend & deduct stock
        $to_item = self::attach_participant_to_order($order, $to_weekend_id, $set_id, $participant_data);
        if (!$to_item) {
            return [
                'success' => false,
                'error'   => __('Produkt docelowego terminu nie istnieje.', 'ak-product-set'),
            ];
        }

        if (function_exists('wc_update_product_stock')) {
            wc_update_product_stock($to_weekend_id, 1, 'decrease');
        }

        $from_prod = wc_get_product($from_weekend_id);
        $to_prod = wc_get_product($to_weekend_id);
        $from_title = $from_prod ? $from_prod->get_name() : (string)$from_weekend_id;
        $to_title = $to_prod ? $to_prod->get_name() : (string)$to_weekend_id;

        $p_name = $participant_data['name'] ?? $participant_uuid;
        $reason = sprintf(
            /* translators: 1: name, 2: from weekend, 3: to weekend */
            __('Zmiana terminu uczestnika %1$s: z "%2$s" na "%3$s"', 'ak-product-set'),
            $p_name,
            $from_title,
            $to_title
        );

        $recalc_result = Order_Recalculator::recalculate($order, [
            'reason'          => $reason,
            'before_snapshot' => $before_snapshot,
        ]);

        return [
            'success'          => true,
            'participant_name' => $p_name,
            'recalculation'    => $recalc_result,
        ];
    }

    /**
     * Find line item corresponding to a specific weekend product ID in the given order.
     */
    public static function find_weekend_item(\WC_Order $order, int $weekend_id): ?\WC_Order_Item_Product {
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === $weekend_id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Save participant metadata on a line item and synchronize display metadata.
     */
    public static function save_item_participants(\WC_Order_Item_Product $item, array $participants): void {
        $item->update_meta_data('_ak_participants', $participants);
        Helper::add_participant_meta_to_order_item($participants, $item);
    }

    /**
     * Attach a participant to an order for a specific weekend (updating existing line item or creating a new decomposed item).
     */
    public static function attach_participant_to_order(\WC_Order $order, int $weekend_id, int $set_id, array $participant_data): ?\WC_Order_Item_Product {
        $target_item = self::find_weekend_item($order, $weekend_id);
        if ($target_item) {
            $participants = (array) $target_item->get_meta('_ak_participants');
            $participants[] = $participant_data;
            self::save_item_participants($target_item, $participants);
            return $target_item;
        }

        $wc_product = wc_get_product($weekend_id);
        if (!$wc_product) {
            return null;
        }

        $new_item = new \WC_Order_Item_Product();
        $new_item->set_product($wc_product);
        $new_item->set_quantity(1);
        if ($set_id > 0) {
            $new_item->add_meta_data('_ak_parent_set_id', $set_id, true);
        }
        $new_item->add_meta_data('_ak_participants', [$participant_data], true);
        self::save_item_participants($new_item, [$participant_data]);
        $order->add_item($new_item);
        return $new_item;
    }
}
