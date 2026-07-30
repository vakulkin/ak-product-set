<?php

namespace AK_Set\Order;

use AK_Set\Models\Weekend_Model;
use AK_Set\Support\Helper;

if (!defined('ABSPATH')) {
    exit;
}

class Order_Decomposer {

    public function init(): void
    {
        // Fire on standard checkout form submission (covers most gateways)
        add_action('woocommerce_checkout_order_processed', [$this, 'decompose_order'], 10, 1);
        // Fire when order reaches 'processing' — catches async payment gateways (Stripe, PayPal, etc.)
        add_action('woocommerce_order_status_processing', [$this, 'decompose_order'], 10, 1);
    }

    /**
     * Decompose composed set order line item into individual single weekend product items
     * and trigger native WooCommerce stock reduction.
     *
     * @param int|\WC_Order $order_id
     */
    public function decompose_order($order_id) {
        $order = $order_id instanceof \WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Prevent double decomposition
        if ($order->get_meta('_ak_order_decomposed')) {
            return;
        }

        $items = $order->get_items();
        $has_decomposed = false;

        foreach ($items as $item_id => $item) {
            if (!($item instanceof \WC_Order_Item_Product)) {
                continue;
            }

            $is_composed = $item->get_meta('_ak_is_composed_set');
            if (!$is_composed) {
                continue;
            }

            $selected_weekends = $item->get_meta('_ak_selected_weekends');
            $headcount = max(1, (int)$item->get_meta('_ak_headcount'));
            $participants = $item->get_meta('_ak_participants');
            $set_id = (int)$item->get_meta('_ak_set_id');
            $round = (int)$item->get_meta('_ak_round');
            $tier = (string)$item->get_meta('_ak_tier');

            if (empty($selected_weekends) || !is_array($selected_weekends)) {
                continue;
            }

            $item_total = (float)$item->get_total();
            $item_subtotal = (float)$item->get_subtotal();
            $count = count($selected_weekends);

            $unit_total = $count > 0 ? round($item_total / $count, 2) : 0;
            $unit_subtotal = $count > 0 ? round($item_subtotal / $count, 2) : 0;

            $accumulated_total = 0;
            $accumulated_subtotal = 0;

            for ($i = 0; $i < $count; $i++) {
                $wid = (int)$selected_weekends[$i];
                $wc_product = wc_get_product($wid);
                if (!$wc_product) {
                    continue;
                }

                if ($i === $count - 1) {
                    $this_total = round($item_total - $accumulated_total, 2);
                    $this_subtotal = round($item_subtotal - $accumulated_subtotal, 2);
                } else {
                    $this_total = $unit_total;
                    $this_subtotal = $unit_subtotal;
                    $accumulated_total += $this_total;
                    $accumulated_subtotal += $this_subtotal;
                }

                $new_item = new \WC_Order_Item_Product();
                $new_item->set_product($wc_product);
                $new_item->set_quantity($headcount);
                $new_item->set_subtotal($this_subtotal);
                $new_item->set_total($this_total);

                $new_item->add_meta_data('_ak_parent_set_id', $set_id, true);
                $new_item->add_meta_data('_ak_headcount', $headcount, true);
                $new_item->add_meta_data('_ak_round', $round, true);
                $new_item->add_meta_data('_ak_tier', $tier, true);

                if (!empty($participants) && is_array($participants)) {
                    Helper::add_participant_meta_to_order_item($participants, $new_item);
                }

                $order->add_item($new_item);
            }

            // Remove parent set line item
            $order->remove_item($item_id);
            $has_decomposed = true;
        }

        if ($has_decomposed) {
            $order->update_meta_data('_ak_order_decomposed', '1');
            $order->save();

            // Trigger native WooCommerce stock reduction for the newly added single product items
            if (function_exists('wc_reduce_stock_levels')) {
                wc_reduce_stock_levels($order);
            }
        }
    }
}
