<?php

namespace AK_Set\Order;

use AK_Set\Support\Helper;

if (!defined('ABSPATH')) {
    exit;
}

class Order_Processor {

    public function init() {
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'transfer_cart_meta_to_order_item'], 10, 4);
    }

    /**
     * Save composed set cart metadata into order line item meta
     *
     * @param \WC_Order_Item_Product $item
     * @param string $cart_item_key
     * @param array $values
     * @param \WC_Order $order
     */
    public function transfer_cart_meta_to_order_item($item, $cart_item_key, $values, $order) {
        if (empty($values['_ak_is_composed_set'])) {
            return;
        }

        $item->add_meta_data('_ak_is_composed_set', true, true);

        if (isset($values['_ak_set_id'])) {
            $set_id = (int)$values['_ak_set_id'];
            $item->add_meta_data('_ak_set_id', $set_id, true);
            $set = new \AK_Set\Models\Set_Model($set_id);
            if ($set->get_title()) {
                $item->set_name($set->get_title());
            }
        }

        if (isset($values['_ak_selected_weekends'])) {
            $item->add_meta_data('_ak_selected_weekends', $values['_ak_selected_weekends'], true);
        }

        if (isset($values['_ak_headcount'])) {
            $item->add_meta_data('_ak_headcount', (int)$values['_ak_headcount'], true);
        }

        if (isset($values['_ak_participants']) && is_array($values['_ak_participants'])) {
            Helper::add_participant_meta_to_order_item($values['_ak_participants'], $item);
        }

        if (isset($values['_ak_applied_price'])) {
            $item->add_meta_data('_ak_applied_price', (float)$values['_ak_applied_price'], true);
        }

        if (isset($values['_ak_round'])) {
            $item->add_meta_data('_ak_round', (int)$values['_ak_round'], true);
        }

        if (isset($values['_ak_tier'])) {
            $item->add_meta_data('_ak_tier', (string)$values['_ak_tier'], true);
        }
    }
}
