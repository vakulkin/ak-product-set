<?php

namespace AK_Set\Cart;

use AK_Set\Models\Set_Model;
use AK_Set\Models\Weekend_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Cart_Display_Filters
{

    public function init()
    {
        add_filter('woocommerce_cart_item_name', [$this, 'filter_cart_item_name'], 10, 3);
        add_filter('woocommerce_get_item_data', [$this, 'filter_item_data'], 10, 2);
        add_filter('woocommerce_cart_item_quantity', [$this, 'filter_cart_item_quantity'], 10, 3);
    }

    /**
     * Customize composed line item title in cart & append Edit Booking link with cart_item_key
     *
     * @param string $name
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_cart_item_name($name, $cart_item, $cart_item_key)
    {
        if (empty($cart_item['_ak_is_composed_set'])) {
            return $name;
        }

        $set_id = isset($cart_item['_ak_set_id']) ? (int)$cart_item['_ak_set_id'] : 0;
        $set = new Set_Model($set_id);
        $set_title = $set->get_title();

        $title_text = $set_title ? $set_title : $name;

        if (function_exists('is_checkout') && is_checkout()) {
            return esc_html($title_text);
        }

        $edit_url = get_permalink($set_id);

        if (empty($edit_url)) {
            return esc_html($title_text);
        }

        $edit_html = sprintf(
            ' <a href="%s" class="ak-edit-booking-link">%s</a>',
            esc_url($edit_url),
            esc_html__('Edytuj rezerwację', 'ak-product-set')
        );

        return esc_html($title_text) . $edit_html;
    }

    /**
     * Render composed line item breakdown metadata in cart table
     *
     * @param array $item_data
     * @param array $cart_item
     * @return array
     */
    public function filter_item_data($item_data, $cart_item)
    {
        if (empty($cart_item['_ak_is_composed_set'])) {
            return $item_data;
        }

        $selected_weekends = isset($cart_item['_ak_selected_weekends']) ? $cart_item['_ak_selected_weekends'] : [];
        $headcount = isset($cart_item['_ak_headcount']) ? (int)$cart_item['_ak_headcount'] : 1;
        $participants = isset($cart_item['_ak_participants']) ? $cart_item['_ak_participants'] : [];

        // 1. Weekends list
        $weekend_titles = [];
        foreach ($selected_weekends as $wid) {
            $w = new Weekend_Model($wid);
            if ($w->get_wc_product()) {
                $weekend_titles[] = $w->get_title();
            }
        }

        if (!empty($weekend_titles)) {
            $item_data[] = [
                'name'  => __('Wybrane Weekendy', 'ak-product-set'),
                'value' => esc_html(implode(', ', $weekend_titles)),
            ];
        }

        // 2. Headcount
        $item_data[] = [
            'name'  => __('Liczba uczestników', 'ak-product-set'),
            'value' => sprintf(__('%d os.', 'ak-product-set'), $headcount),
        ];

        // 3. Participants summary
        if (!empty($participants)) {
            $rows = [];
            foreach ($participants as $idx => $p) {
                if (!empty($p['name'])) {
                    $details = '<strong>' . esc_html($p['name']) . '</strong>';
                    if (!empty($p['tshirt_size'])) {
                        $cut = (!empty($p['tshirt_cut']) && $p['tshirt_cut'] === 'women') ? __('damska', 'ak-product-set') : __('męska', 'ak-product-set');
                        $details .= ' <span style="opacity:0.8;">(Koszulka: ' . esc_html($p['tshirt_size']) . ' ' . $cut . ')</span>';
                    }
                    $rows[] = '<li style="margin-bottom:2px;">' . $details . '</li>';
                }
            }
            if (!empty($rows)) {
                $item_data[] = [
                    'name'  => __('Uczestnicy', 'ak-product-set'),
                    'value' => '<ul style="margin:4px 0 0 16px; padding:0; list-style-type:disc;">' . implode('', $rows) . '</ul>',
                ];
            }
        }

        return $item_data;
    }

    /**
     * Lock quantity input for composed set cart item to 1
     *
     * @param string $product_quantity
     * @param string $cart_item_key
     * @param array $cart_item
     * @return string
     */
    public function filter_cart_item_quantity($product_quantity, $cart_item_key, $cart_item)
    {
        if (!empty($cart_item['_ak_is_composed_set'])) {
            return '1 <input type="hidden" name="cart[' . esc_attr($cart_item_key) . '][qty]" value="1" />';
        }

        return $product_quantity;
    }
}
