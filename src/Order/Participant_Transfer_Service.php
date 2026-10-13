<?php

namespace AK_Set\Order;

use AK_Set\Support\Helper;
use AK_Set\Models\Participant_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Participant_Transfer_Service {

    public const TRANSFER_TYPE_NEW      = 'new';
    public const TRANSFER_TYPE_EXISTING = 'existing';

    /**
     * Get transfer type choices
     *
     * @return array<string, string>
     */
    public static function get_transfer_types(): array {
        return [
            self::TRANSFER_TYPE_NEW      => __('Utwórz nowe zamówienie (puste) i przenieś', 'ak-product-set'),
            self::TRANSFER_TYPE_EXISTING => __('Przenieś do istniejącego zamówienia', 'ak-product-set'),
        ];
    }

    /**
     * Remove a specific single weekend enrollment for a participant.
     *
     * @param \WC_Order $order
     * @param string $participant_uuid
     * @param int $weekend_id
     * @param bool $tshirt_retained
     * @return array
     */
    public static function remove_weekend_for_participant(\WC_Order $order, string $participant_uuid, int $weekend_id, bool $tshirt_retained = false): array {
        $participant_name = '';
        $found = false;

        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof \WC_Order_Item_Product) || (int) $item->get_product_id() !== $weekend_id) {
                continue;
            }

            $participants = $item->get_meta('_ak_participants');
            if (!is_array($participants)) {
                continue;
            }

            $remaining = [];
            foreach ($participants as $p) {
                if (!empty($p['id']) && $p['id'] === $participant_uuid) {
                    $found = true;
                    if ($participant_name === '' && !empty($p['name'])) {
                        $participant_name = (string) $p['name'];
                    }
                } else {
                    $remaining[] = $p;
                }
            }

            if ($found) {
                $item->update_meta_data('_ak_participants', $remaining);
                Helper::add_participant_meta_to_order_item($remaining, $item);

                // Restore stock for this single weekend
                if (function_exists('wc_update_product_stock')) {
                    wc_update_product_stock($weekend_id, 1, 'increase');
                }
                break;
            }
        }

        if (!$found) {
            return [
                'success' => false,
                'error'   => __('Uczestnik nie został znaleziony w wybranym terminie.', 'ak-product-set'),
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
            'reason'               => $reason,
            'retained_tshirt_cost' => $tshirt_cost,
        ]);

        return [
            'success'          => true,
            'participant_name' => $participant_name,
            'recalculation'    => $recalc_result,
        ];
    }

    /**
     * Remove a participant completely from all weekends in an order.
     *
     * @param \WC_Order $order
     * @param string $participant_uuid
     * @param bool $tshirt_retained
     * @return array
     */
    public static function remove_participant_completely(\WC_Order $order, string $participant_uuid, bool $tshirt_retained = false): array {
        $participant_name = '';
        $removed_count = 0;

        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $participants = $item->get_meta('_ak_participants');
            if (!is_array($participants)) {
                continue;
            }

            $remaining = [];
            $found_in_item = false;

            foreach ($participants as $p) {
                if (!empty($p['id']) && $p['id'] === $participant_uuid) {
                    $found_in_item = true;
                    if ($participant_name === '' && !empty($p['name'])) {
                        $participant_name = (string) $p['name'];
                    }
                } else {
                    $remaining[] = $p;
                }
            }

            if ($found_in_item) {
                $removed_count++;
                $item->update_meta_data('_ak_participants', $remaining);
                Helper::add_participant_meta_to_order_item($remaining, $item);

                $product_id = (int) $item->get_product_id();
                if ($product_id > 0 && function_exists('wc_update_product_stock')) {
                    wc_update_product_stock($product_id, 1, 'increase');
                }
            }
        }

        if ($removed_count === 0) {
            return [
                'success' => false,
                'error'   => __('Uczestnik nie został znaleziony w tym zamówieniu.', 'ak-product-set'),
            ];
        }

        $tshirt_cost = $tshirt_retained ? Order_Recalculator::get_tshirt_cost() : 0.0;
        $reason = sprintf(
            /* translators: %s: participant name */
            __('Całkowite anulowanie uczestnika ze wszystkich terminów: %s', 'ak-product-set'),
            $participant_name ?: $participant_uuid
        );

        if ($tshirt_retained && $tshirt_cost > 0) {
            $reason .= sprintf(' (potrącono koszt koszulki: %s zł)', number_format_i18n($tshirt_cost, 2));
        }

        $recalc_result = Order_Recalculator::recalculate($order, [
            'reason'               => $reason,
            'retained_tshirt_cost' => $tshirt_cost,
        ]);

        return [
            'success'          => true,
            'participant_name' => $participant_name,
            'recalculation'    => $recalc_result,
        ];
    }

    /**
     * Add a participant with selectable weekend enrollment.
     *
     * @param \WC_Order $order
     * @param int $set_id
     * @param array $participant_data
     * @param array $selected_weekend_ids Empty array adds to all weekends of the set in order
     * @return array
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

        foreach ($target_wids as $wid) {
            if (isset($existing_items_by_wid[$wid])) {
                $item = $existing_items_by_wid[$wid];
                $participants = (array) $item->get_meta('_ak_participants');
                $participants[] = $p_record;
                $item->update_meta_data('_ak_participants', $participants);
                Helper::add_participant_meta_to_order_item($participants, $item);
            } else {
                $wc_product = wc_get_product($wid);
                if (!$wc_product) {
                    continue;
                }
                $new_item = new \WC_Order_Item_Product();
                $new_item->set_product($wc_product);
                $new_item->set_quantity(1);
                $new_item->add_meta_data('_ak_parent_set_id', $set_id, true);
                $new_item->add_meta_data('_ak_participants', [$p_record], true);
                Helper::add_participant_meta_to_order_item([$p_record], $new_item);
                $order->add_item($new_item);
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

        if (in_array($order->get_status(), ['cancelled', 'wc-cancelled', 'trash'], true)) {
            $order->update_status('processing', sprintf(
                /* translators: %s: participant name */
                __('Przywrócono zamówienie po dodaniu uczestnika %s', 'ak-product-set'),
                $p_model->name ?: $p_model->id
            ));
        }

        $reason = sprintf(
            /* translators: 1: name, 2: count */
            __('Dodanie uczestnika: %1$s (wybrane terminy: %2$d)', 'ak-product-set'),
            $p_model->name ?: $p_model->id,
            $updated_items
        );

        $recalc_result = Order_Recalculator::recalculate($order, [
            'reason' => $reason,
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
     *
     * @param \WC_Order $order
     * @param string $participant_uuid
     * @param int $from_weekend_id
     * @param int $to_weekend_id
     * @return array
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
                        if (!empty($p['id']) && $p['id'] === $participant_uuid) {
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
                if (!empty($p['id']) && $p['id'] === $participant_uuid) {
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

        // 1. Remove from source weekend & restore stock
        $from_participants = (array) $from_item->get_meta('_ak_participants');
        $from_remaining = array_values(array_filter($from_participants, fn($p) => ($p['id'] ?? '') !== $participant_uuid));
        $from_item->update_meta_data('_ak_participants', $from_remaining);
        Helper::add_participant_meta_to_order_item($from_remaining, $from_item);

        if (function_exists('wc_update_product_stock')) {
            wc_update_product_stock($from_weekend_id, 1, 'increase');
        }

        // If source item now has 0 participants, delete it
        if (empty($from_remaining)) {
            $order->remove_item($from_item->get_id());
        }

        // 2. Add to destination weekend & deduct stock
        if ($to_item) {
            $to_participants = (array) $to_item->get_meta('_ak_participants');
            $to_participants[] = $participant_data;
            $to_item->update_meta_data('_ak_participants', $to_participants);
            Helper::add_participant_meta_to_order_item($to_participants, $to_item);
        } else {
            $wc_product = wc_get_product($to_weekend_id);
            if (!$wc_product) {
                return [
                    'success' => false,
                    'error'   => __('Produkt docelowego terminu nie istnieje.', 'ak-product-set'),
                ];
            }
            $new_item = new \WC_Order_Item_Product();
            $new_item->set_product($wc_product);
            $new_item->set_quantity(1);
            $new_item->add_meta_data('_ak_parent_set_id', $set_id, true);
            $new_item->add_meta_data('_ak_participants', [$participant_data], true);
            Helper::add_participant_meta_to_order_item([$participant_data], $new_item);
            $order->add_item($new_item);
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
            'reason' => $reason,
        ]);

        return [
            'success'          => true,
            'participant_name' => $p_name,
            'recalculation'    => $recalc_result,
        ];
    }

    /**
     * Transfer a single weekend (or all weekends) of a participant to a new order.
     *
     * @param \WC_Order $source_order
     * @param string $participant_uuid
     * @param int|null $specific_weekend_id If null, transfers all weekends of this participant
     * @return array
     */
    public static function transfer_to_new_order(\WC_Order $source_order, string $participant_uuid, ?int $specific_weekend_id = null): array {
        $participant_data = null;
        $set_id = 0;
        $weekends_to_transfer = [];

        foreach ($source_order->get_items('line_item') as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $wid = (int) $item->get_product_id();
            if ($specific_weekend_id !== null && $wid !== $specific_weekend_id) {
                continue;
            }

            $participants = $item->get_meta('_ak_participants');
            if (is_array($participants)) {
                foreach ($participants as $p) {
                    if (!empty($p['id']) && $p['id'] === $participant_uuid) {
                        $participant_data = $p;
                        $set_id = (int) $item->get_meta('_ak_parent_set_id');
                        $weekends_to_transfer[] = $wid;
                        break;
                    }
                }
            }
        }

        if (!$participant_data || empty($weekends_to_transfer)) {
            return [
                'success' => false,
                'error'   => __('Uczestnik / termin nie został odnaleziony w zamówieniu źródłowym.', 'ak-product-set'),
            ];
        }

        // 1. Remove transferred weekend(s) from source order
        foreach ($weekends_to_transfer as $wid) {
            self::remove_weekend_for_participant($source_order, $participant_uuid, $wid, false);
        }

        // 2. Create new WC Order with identical billing customer details
        $new_order = wc_create_order([
            'status'        => $source_order->get_status(),
            'customer_id'   => $source_order->get_customer_id(),
            'customer_note' => $source_order->get_customer_note(),
        ]);

        if (is_wp_error($new_order) || !$new_order) {
            return [
                'success' => false,
                'error'   => __('Nie udało się utworzyć nowego zamówienia.', 'ak-product-set'),
            ];
        }

        // Copy billing & shipping addresses
        $new_order->set_address($source_order->get_address('billing'), 'billing');
        $new_order->set_address($source_order->get_address('shipping'), 'shipping');
        $new_order->set_payment_method($source_order->get_payment_method());
        $new_order->set_payment_method_title($source_order->get_payment_method_title());

        // 3. Add decomposed line items for transferred weekend(s)
        foreach ($weekends_to_transfer as $wid) {
            $wc_product = wc_get_product($wid);
            if (!$wc_product) {
                continue;
            }

            $new_item = new \WC_Order_Item_Product();
            $new_item->set_product($wc_product);
            $new_item->set_quantity(1);
            $new_item->add_meta_data('_ak_parent_set_id', $set_id, true);
            $new_item->add_meta_data('_ak_headcount', 1, true);
            $new_item->add_meta_data('_ak_participants', [$participant_data], true);
            Helper::add_participant_meta_to_order_item([$participant_data], $new_item);
            $new_order->add_item($new_item);

            if (function_exists('wc_update_product_stock')) {
                wc_update_product_stock($wid, 1, 'decrease');
            }
        }

        $new_order->update_meta_data('_ak_order_decomposed', '1');
        $new_order->update_meta_data('_ak_transferred_from_order', $source_order->get_id());

        $existing_to = $source_order->get_meta('_ak_transferred_to_orders');
        if (!is_array($existing_to)) {
            $existing_to = array_filter([(int)$source_order->get_meta('_ak_transferred_to_order')]);
        }
        if (!in_array($new_order->get_id(), $existing_to, true)) {
            $existing_to[] = $new_order->get_id();
        }
        $source_order->update_meta_data('_ak_transferred_to_orders', $existing_to);
        $source_order->update_meta_data('_ak_transferred_to_order', $new_order->get_id());

        // 4. Recalculate the new order
        $p_name = $participant_data['name'] ?? $participant_uuid;
        $new_recalc = Order_Recalculator::recalculate($new_order, [
            'reason' => sprintf(
                /* translators: 1: name, 2: order id */
                __('Utworzono z przeniesienia uczestnika %1$s z zamówienia #%2$d', 'ak-product-set'),
                $p_name,
                $source_order->get_id()
            ),
        ]);

        // 5. Recalculate the source order to update remaining items, balances and overpayments
        $source_recalc = Order_Recalculator::recalculate($source_order, [
            'reason' => sprintf(
                /* translators: 1: name, 2: order id */
                __('Przeniesienie uczestnika %1$s do nowego zamówienia #%2$d', 'ak-product-set'),
                $p_name,
                $new_order->get_id()
            ),
        ]);

        $source_order->add_order_note(sprintf(
            /* translators: 1: name, 2: order id, 3: weekends */
            __('Uczestnik %1$s (terminy: %3$s) został przeniesiony do nowego zamówienia #%2$d.', 'ak-product-set'),
            $p_name,
            $new_order->get_id(),
            implode(', ', $weekends_to_transfer)
        ));
        $source_order->save();

        $new_order->add_order_note(sprintf(
            /* translators: 1: name, 2: order id */
            __('Zamówienie utworzone w wyniku przeniesienia uczestnika %1$s z zamówienia #%2$d.', 'ak-product-set'),
            $p_name,
            $source_order->get_id()
        ));
        $new_order->save();

        return [
            'success'          => true,
            'new_order_id'     => $new_order->get_id(),
            'participant_name' => $p_name,
            'new_recalc'       => $new_recalc,
            'source_recalc'    => $source_recalc,
        ];
    }

    /**
     * Transfer a single weekend (or all weekends) of a participant to an existing order.
     *
     * @param \WC_Order $source_order
     * @param \WC_Order $target_order
     * @param string $participant_uuid
     * @param int|null $specific_weekend_id If null, transfers all weekends of this participant
     * @return array
     */
    public static function transfer_to_existing_order(\WC_Order $source_order, \WC_Order $target_order, string $participant_uuid, ?int $specific_weekend_id = null): array {
        if ($source_order->get_id() === $target_order->get_id()) {
            return [
                'success' => false,
                'error'   => __('Zamówienie źródłowe i docelowe nie mogą być tym samym zamówieniem.', 'ak-product-set'),
            ];
        }

        $participant_data = null;
        $set_id = 0;
        $weekends_to_transfer = [];

        foreach ($source_order->get_items('line_item') as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $wid = (int) $item->get_product_id();
            if ($specific_weekend_id !== null && $wid !== $specific_weekend_id) {
                continue;
            }

            $participants = $item->get_meta('_ak_participants');
            if (is_array($participants)) {
                foreach ($participants as $p) {
                    if (!empty($p['id']) && $p['id'] === $participant_uuid) {
                        $participant_data = $p;
                        $set_id = (int) $item->get_meta('_ak_parent_set_id');
                        $weekends_to_transfer[] = $wid;
                        break;
                    }
                }
            }
        }

        if (!$participant_data || empty($weekends_to_transfer)) {
            return [
                'success' => false,
                'error'   => __('Uczestnik / termin nie został odnaleziony w zamówieniu źródłowym.', 'ak-product-set'),
            ];
        }

        // Prevent adding duplicate enrollment to target order
        foreach ($weekends_to_transfer as $wid) {
            foreach ($target_order->get_items('line_item') as $item) {
                if ((int)$item->get_product_id() === $wid) {
                    $target_participants = (array) $item->get_meta('_ak_participants');
                    foreach ($target_participants as $p) {
                        if (!empty($p['id']) && $p['id'] === $participant_uuid) {
                            $p_name = $participant_data['name'] ?? $participant_uuid;
                            return [
                                'success' => false,
                                'error'   => sprintf(
                                    __('Uczestnik "%s" jest już zapisany na termin #%d w zamówieniu docelowym.', 'ak-product-set'),
                                    $p_name,
                                    $wid
                                ),
                            ];
                        }
                    }
                }
            }
        }

        // 1. Remove from source order
        foreach ($weekends_to_transfer as $wid) {
            self::remove_weekend_for_participant($source_order, $participant_uuid, $wid, false);
        }

        // 2. Add to target order
        foreach ($weekends_to_transfer as $wid) {
            $target_item = null;
            foreach ($target_order->get_items('line_item') as $item) {
                if ((int)$item->get_product_id() === $wid) {
                    $target_item = $item;
                    break;
                }
            }

            if ($target_item) {
                $target_participants = (array) $target_item->get_meta('_ak_participants');
                $target_participants[] = $participant_data;
                $target_item->update_meta_data('_ak_participants', $target_participants);
                Helper::add_participant_meta_to_order_item($target_participants, $target_item);
            } else {
                $wc_product = wc_get_product($wid);
                if ($wc_product) {
                    $new_item = new \WC_Order_Item_Product();
                    $new_item->set_product($wc_product);
                    $new_item->set_quantity(1);
                    $new_item->add_meta_data('_ak_parent_set_id', $set_id, true);
                    $new_item->add_meta_data('_ak_participants', [$participant_data], true);
                    Helper::add_participant_meta_to_order_item([$participant_data], $new_item);
                    $target_order->add_item($new_item);
                }
            }

            if (function_exists('wc_update_product_stock')) {
                wc_update_product_stock($wid, 1, 'decrease');
            }
        }

        $p_name = $participant_data['name'] ?? $participant_uuid;

        // Track transfer cross-references
        $existing_to = $source_order->get_meta('_ak_transferred_to_orders');
        if (!is_array($existing_to)) {
            $existing_to = array_filter([(int)$source_order->get_meta('_ak_transferred_to_order')]);
        }
        if (!in_array($target_order->get_id(), $existing_to, true)) {
            $existing_to[] = $target_order->get_id();
        }
        $source_order->update_meta_data('_ak_transferred_to_orders', $existing_to);
        $source_order->update_meta_data('_ak_transferred_to_order', $target_order->get_id());

        $existing_from = $target_order->get_meta('_ak_transferred_from_orders');
        if (!is_array($existing_from)) {
            $existing_from = array_filter([(int)$target_order->get_meta('_ak_transferred_from_order')]);
        }
        if (!in_array($source_order->get_id(), $existing_from, true)) {
            $existing_from[] = $source_order->get_id();
        }
        $target_order->update_meta_data('_ak_transferred_from_orders', $existing_from);
        $target_order->update_meta_data('_ak_transferred_from_order', $source_order->get_id());

        // 3. Recalculate the target order
        $target_recalc = Order_Recalculator::recalculate($target_order, [
            'reason' => sprintf(
                /* translators: 1: name, 2: order id */
                __('Przeniesiono uczestnika %1$s z zamówienia #%2$d', 'ak-product-set'),
                $p_name,
                $source_order->get_id()
            ),
        ]);

        // 4. Recalculate the source order to update remaining items, balances and overpayments
        $source_recalc = Order_Recalculator::recalculate($source_order, [
            'reason' => sprintf(
                /* translators: 1: name, 2: order id */
                __('Przeniesienie uczestnika %1$s do zamówienia #%2$d', 'ak-product-set'),
                $p_name,
                $target_order->get_id()
            ),
        ]);

        $source_order->add_order_note(sprintf(
            /* translators: 1: name, 2: order id, 3: weekends */
            __('Uczestnik %1$s (terminy: %3$s) został przeniesiony do zamówienia #%2$d.', 'ak-product-set'),
            $p_name,
            $target_order->get_id(),
            implode(', ', $weekends_to_transfer)
        ));
        $source_order->save();

        $target_order->add_order_note(sprintf(
            /* translators: 1: name, 2: order id, 3: weekends */
            __('Uczestnik %1$s (terminy: %3$s) został dołączony z zamówienia #%2$d.', 'ak-product-set'),
            $p_name,
            $source_order->get_id(),
            implode(', ', $weekends_to_transfer)
        ));
        $target_order->save();

        return [
            'success'          => true,
            'target_order_id'  => $target_order->get_id(),
            'participant_name' => $p_name,
            'target_recalc'    => $target_recalc,
            'source_recalc'    => $source_recalc,
        ];
    }

    /**
     * Backward-compatible alias for remove_participant_completely.
     */
    public static function remove_participant(\WC_Order $order, string $participant_uuid, bool $tshirt_retained = false): array {
        return self::remove_participant_completely($order, $participant_uuid, $tshirt_retained);
    }

    /**
     * Backward-compatible alias for reschedule_weekend_for_participant.
     */
    public static function reschedule_participant(\WC_Order $order, string $participant_uuid, int $from_weekend_id, int $to_weekend_id): array {
        return self::reschedule_weekend_for_participant($order, $participant_uuid, $from_weekend_id, $to_weekend_id);
    }

    /**
     * Update participant's full details (name, email, phone, tshirt) across all line items in an order without price recalculation.
     *
     * @param \WC_Order $order
     * @param string    $participant_uuid
     * @param array     $data ['name' => ..., 'email' => ..., 'phone' => ..., 'tshirt_size' => ..., 'tshirt_cut' => ...]
     * @return array
     */
    public static function update_participant_data(\WC_Order $order, string $participant_uuid, array $data): array {
        $found = false;
        $participant_name = '';
        $old_data = [];
        $new_data = [];

        $has_name_change  = isset($data['name']) && trim((string) $data['name']) !== '';
        $has_email_change = isset($data['email']);
        $has_phone_change = isset($data['phone']);
        $has_size_change  = isset($data['tshirt_size']);
        $has_cut_change   = isset($data['tshirt_cut']);

        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $participants = $item->get_meta('_ak_participants');
            if (!is_array($participants)) {
                continue;
            }

            $modified = false;
            foreach ($participants as &$p) {
                if (!empty($p['id']) && $p['id'] === $participant_uuid) {
                    $found = true;
                    if (empty($old_data)) {
                        $old_data = [
                            'name'        => $p['name'] ?? '',
                            'email'       => $p['email'] ?? '',
                            'phone'       => $p['phone'] ?? '',
                            'tshirt_size' => $p['tshirt_size'] ?? '',
                            'tshirt_cut'  => $p['tshirt_cut'] ?? 'men',
                        ];
                    }

                    if ($has_name_change) {
                        $p['name'] = sanitize_text_field($data['name']);
                    }
                    if ($has_email_change) {
                        $p['email'] = sanitize_email($data['email']);
                    }
                    if ($has_phone_change) {
                        $p['phone'] = sanitize_text_field($data['phone']);
                    }
                    if ($has_size_change) {
                        $p['tshirt_size'] = sanitize_text_field($data['tshirt_size']);
                    }
                    if ($has_cut_change) {
                        $p['tshirt_cut'] = sanitize_text_field($data['tshirt_cut']);
                    }

                    $new_data = [
                        'name'        => $p['name'] ?? '',
                        'email'       => $p['email'] ?? '',
                        'phone'       => $p['phone'] ?? '',
                        'tshirt_size' => $p['tshirt_size'] ?? '',
                        'tshirt_cut'  => $p['tshirt_cut'] ?? '',
                    ];
                    $participant_name = $p['name'] ?? '';
                    $modified = true;
                }
            }
            unset($p);

            if ($modified) {
                $item->update_meta_data('_ak_participants', $participants);
                Helper::add_participant_meta_to_order_item($participants, $item);
            }
        }

        if (!$found) {
            return [
                'success' => false,
                'error'   => __('Uczestnik nie został znaleziony w tym zamówieniu.', 'ak-product-set'),
            ];
        }

        $changes = [];
        if (($old_data['name'] ?? '') !== ($new_data['name'] ?? '')) {
            $changes[] = sprintf('Imię: "%s" → "%s"', $old_data['name'] ?: '—', $new_data['name']);
        }
        if (($old_data['email'] ?? '') !== ($new_data['email'] ?? '')) {
            $changes[] = sprintf('E-mail: "%s" → "%s"', $old_data['email'] ?: '—', $new_data['email'] ?: '—');
        }
        if (($old_data['phone'] ?? '') !== ($new_data['phone'] ?? '')) {
            $changes[] = sprintf('Telefon: "%s" → "%s"', $old_data['phone'] ?: '—', $new_data['phone'] ?: '—');
        }
        if (($old_data['tshirt_size'] ?? '') !== ($new_data['tshirt_size'] ?? '') || ($old_data['tshirt_cut'] ?? '') !== ($new_data['tshirt_cut'] ?? '')) {
            $old_cut_label = Helper::get_tshirt_cut_label($old_data['tshirt_cut'] ?? '');
            $new_cut_label = Helper::get_tshirt_cut_label($new_data['tshirt_cut'] ?? '');
            $changes[] = sprintf(
                'Koszulka: %s [%s] → %s [%s]',
                $old_data['tshirt_size'] ?: '—',
                $old_cut_label,
                $new_data['tshirt_size'] ?: '—',
                $new_cut_label
            );
        }

        if ($has_size_change && $has_cut_change && !$has_name_change && !$has_email_change && !$has_phone_change) {
            $old_cut_label = Helper::get_tshirt_cut_label($old_data['tshirt_cut'] ?? '');
            $new_cut_label = Helper::get_tshirt_cut_label($new_data['tshirt_cut'] ?? '');
            $log_entry = sprintf(
                "[%s] Zmiana koszulki dla uczestnika %s:\n• PRZED: Rozmiar %s [%s]\n• PO: Rozmiar %s [%s]",
                current_time('Y-m-d H:i'),
                $participant_name ?: $participant_uuid,
                $old_data['tshirt_size'] ?: '—',
                $old_cut_label,
                $new_data['tshirt_size'] ?: '—',
                $new_cut_label
            );
        } else {
            $log_entry = sprintf(
                "[%s] Edycja danych uczestnika %s:\n• %s",
                current_time('Y-m-d H:i'),
                $participant_name ?: $participant_uuid,
                !empty($changes) ? implode("\n• ", $changes) : __('Brak zmian', 'ak-product-set')
            );
        }

        Order_Recalculator::append_audit_log($order, $log_entry);
        $order->save();

        return [
            'success'          => true,
            'participant_name' => $participant_name,
            'new_size'         => $new_data['tshirt_size'] ?? '',
            'new_cut'          => $new_data['tshirt_cut'] ?? '',
            'participant_data' => $new_data,
        ];
    }

    /**
     * Update participant's T-shirt size and cut across all line items in an order without price recalculation.
     *
     * @param \WC_Order $order
     * @param string $participant_uuid
     * @param string $new_size
     * @param string $new_cut
     * @return array
     */
    public static function update_participant_tshirt(\WC_Order $order, string $participant_uuid, string $new_size, string $new_cut): array {
        return self::update_participant_data($order, $participant_uuid, [
            'tshirt_size' => $new_size,
            'tshirt_cut'  => $new_cut,
        ]);
    }
}

