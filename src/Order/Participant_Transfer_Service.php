<?php

namespace AK_Set\Order;

use AK_Set\Support\Helper;

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
     * Transfer a single weekend (or all weekends) of a participant to a new order.
     *
     * @param int|null $specific_weekend_id If null, transfers all weekends of this participant
     */
    public static function transfer_to_new_order(\WC_Order $source_order, string $participant_uuid, ?int $specific_weekend_id = null): array {
        $search = self::find_participant_weekends_in_order($source_order, $participant_uuid, $specific_weekend_id);
        $participant_data = $search['participant_data'];
        $set_id = $search['set_id'];
        $weekends_to_transfer = $search['weekends'];

        if (!$participant_data || empty($weekends_to_transfer)) {
            return [
                'success' => false,
                'error'   => __('Uczestnik / termin nie został odnaleziony w zamówieniu źródłowym.', 'ak-product-set'),
            ];
        }

        $source_before_snapshot = Order_Recalculator::get_order_state_snapshot($source_order);

        // 1. Remove transferred weekend(s) from source order (without intermediate recalculations)
        foreach ($weekends_to_transfer as $wid) {
            Order_Participant_Slot_Manager::remove_weekend_for_participant($source_order, $participant_uuid, $wid, false, false);
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

        self::record_outgoing_transfer_link($source_order, $new_order->get_id());

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
            'before_snapshot' => $source_before_snapshot,
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
     * @param int|null $specific_weekend_id If null, transfers all weekends of this participant
     */
    public static function transfer_to_existing_order(\WC_Order $source_order, \WC_Order $target_order, string $participant_uuid, ?int $specific_weekend_id = null): array {
        if ($source_order->get_id() === $target_order->get_id()) {
            return [
                'success' => false,
                'error'   => __('Zamówienie źródłowe i docelowe nie mogą być tym samym zamówieniem.', 'ak-product-set'),
            ];
        }

        $search = self::find_participant_weekends_in_order($source_order, $participant_uuid, $specific_weekend_id);
        $participant_data = $search['participant_data'];
        $set_id = $search['set_id'];
        $weekends_to_transfer = $search['weekends'];

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

        // 1. Remove from source order (without intermediate recalculations)
        $source_before_snapshot = Order_Recalculator::get_order_state_snapshot($source_order);
        $target_before_snapshot = Order_Recalculator::get_order_state_snapshot($target_order);
        foreach ($weekends_to_transfer as $wid) {
            Order_Participant_Slot_Manager::remove_weekend_for_participant($source_order, $participant_uuid, $wid, false, false);
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
        self::record_outgoing_transfer_link($source_order, $target_order->get_id());
        self::record_incoming_transfer_link($target_order, $source_order->get_id());

        // 3. Recalculate the target order
        $target_recalc = Order_Recalculator::recalculate($target_order, [
            'reason' => sprintf(
                /* translators: 1: name, 2: order id */
                __('Przeniesiono uczestnika %1$s z zamówienia #%2$d', 'ak-product-set'),
                $p_name,
                $source_order->get_id()
            ),
            'before_snapshot' => $target_before_snapshot,
        ]);

        // 4. Recalculate the source order to update remaining items, balances and overpayments
        $source_recalc = Order_Recalculator::recalculate($source_order, [
            'reason' => sprintf(
                /* translators: 1: name, 2: order id */
                __('Przeniesienie uczestnika %1$s do zamówienia #%2$d', 'ak-product-set'),
                $p_name,
                $target_order->get_id()
            ),
            'before_snapshot' => $source_before_snapshot,
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
     * Locate participant data, parent set ID, and enrolled weekend product IDs in an order.
     *
     * @return array{participant_data: ?array, set_id: int, weekends: int[]}
     */
    private static function find_participant_weekends_in_order(\WC_Order $order, string $participant_uuid, ?int $specific_weekend_id = null): array {
        $participant_data = null;
        $set_id = 0;
        $weekends = [];

        foreach ($order->get_items('line_item') as $item) {
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
                        $weekends[] = $wid;
                        break;
                    }
                }
            }
        }

        return [
            'participant_data' => $participant_data,
            'set_id'           => $set_id,
            'weekends'         => $weekends,
        ];
    }

    /**
     * Record outgoing transfer reference on source order.
     */
    private static function record_outgoing_transfer_link(\WC_Order $order, int $to_order_id): void {
        $existing = $order->get_meta('_ak_transferred_to_orders');
        if (!is_array($existing)) {
            $existing = array_filter([(int) $order->get_meta('_ak_transferred_to_order')]);
        }
        if (!in_array($to_order_id, $existing, true)) {
            $existing[] = $to_order_id;
        }
        $order->update_meta_data('_ak_transferred_to_orders', $existing);
        $order->update_meta_data('_ak_transferred_to_order', $to_order_id);
    }

    /**
     * Record incoming transfer reference on target order.
     */
    private static function record_incoming_transfer_link(\WC_Order $order, int $from_order_id): void {
        $existing = $order->get_meta('_ak_transferred_from_orders');
        if (!is_array($existing)) {
            $existing = array_filter([(int) $order->get_meta('_ak_transferred_from_order')]);
        }
        if (!in_array($from_order_id, $existing, true)) {
            $existing[] = $from_order_id;
        }
        $order->update_meta_data('_ak_transferred_from_orders', $existing);
        $order->update_meta_data('_ak_transferred_from_order', $from_order_id);
    }
}

