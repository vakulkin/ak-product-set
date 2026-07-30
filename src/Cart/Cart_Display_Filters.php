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
        add_filter('woocommerce_widget_cart_item_quantity', [$this, 'filter_widget_cart_item_quantity'], 10, 3);
        add_filter('woocommerce_cart_item_price', [$this, 'filter_cart_item_price'], 10, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'filter_cart_item_subtotal'], 10, 3);
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

        // 1. Wybrane Weekendy (Formatted List)
        $weekend_titles = [];
        foreach ($selected_weekends as $wid) {
            $w = new Weekend_Model($wid);
            if ($w->get_wc_product()) {
                $weekend_titles[] = $w->get_title();
            }
        }

        if (!empty($weekend_titles)) {
            $weekend_rows = [];
            foreach ($weekend_titles as $title) {
                $weekend_rows[] = sprintf(
                    '<li style="margin-bottom:3px; padding-left:12px; position:relative; line-height:1.45;"><span style="position:absolute; left:0; color:#71717a; font-size:12px;">•</span> %s</li>',
                    esc_html($title)
                );
            }
            $item_data[] = [
                'name'  => __('Wybrane Weekendy', 'ak-product-set'),
                'value' => '<ul style="margin:4px 0 6px 0; padding:0; list-style:none;">' . implode('', $weekend_rows) . '</ul>',
            ];
        }

        // 2. Liczba uczestników
        $item_data[] = [
            'name'  => __('Liczba uczestników', 'ak-product-set'),
            'value' => sprintf(__('%d os.', 'ak-product-set'), $headcount),
        ];

        // 3. Uczestnicy (Clean Structured Text Layout)
        if (!empty($participants)) {
            $participant_rows = [];
            foreach ($participants as $idx => $p) {
                if (empty($p['name'])) {
                    continue;
                }

                $details_parts = [];
                if (!empty($p['email'])) {
                    $details_parts[] = esc_html($p['email']);
                }
                if (!empty($p['phone'])) {
                    $details_parts[] = esc_html($p['phone']);
                }
                if (!empty($p['tshirt_size'])) {
                    $cut = (!empty($p['tshirt_cut']) && $p['tshirt_cut'] === 'women')
                        ? __('damska', 'ak-product-set')
                        : __('męska', 'ak-product-set');
                    $details_parts[] = sprintf(__('Koszulka: %1$s (%2$s)', 'ak-product-set'), esc_html($p['tshirt_size']), $cut);
                }

                $meta_line = !empty($details_parts)
                    ? sprintf('<div style="font-size:12px; color:#52525b; margin-top:2px; line-height:1.4;">%s</div>', implode(' &bull; ', $details_parts))
                    : '';

                $participant_rows[] = sprintf(
                    '<li style="margin-bottom:8px; padding-left:12px; position:relative; line-height:1.4;"><span style="position:absolute; left:0; color:#71717a; font-size:12px;">•</span> <strong style="color:#18181b; font-weight:600;">%s</strong>%s</li>',
                    esc_html($p['name']),
                    $meta_line
                );
            }

            if (!empty($participant_rows)) {
                $item_data[] = [
                    'name'  => __('Uczestnicy', 'ak-product-set'),
                    'value' => '<ul style="margin:4px 0 0 0; padding:0; list-style:none;">' . implode('', $participant_rows) . '</ul>',
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

    /**
     * Filter mini cart widget quantity and price display string
     *
     * @param string $html
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_widget_cart_item_quantity($html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['_ak_is_composed_set'])) {
            $applied_price = isset($cart_item['_ak_applied_price']) ? (float)$cart_item['_ak_applied_price'] : 0.0;
            if ($applied_price <= 0 && isset($cart_item['_ak_set_id']) && isset($cart_item['_ak_selected_weekends']) && isset($cart_item['_ak_headcount'])) {
                $calc = \AK_Set\Pricing\Pricing_Engine::calculate((int)$cart_item['_ak_set_id'], $cart_item['_ak_selected_weekends'], (int)$cart_item['_ak_headcount']);
                if ($calc['valid'] && $calc['total_price'] > 0) {
                    $applied_price = (float)$calc['total_price'];
                }
            }

            if ($applied_price > 0 && function_exists('wc_price')) {
                return '<span class="quantity">1 &times; ' . wc_price($applied_price) . '</span>';
            }
        }

        return $html;
    }

    /**
     * Filter cart line item unit price display HTML
     *
     * @param string $price_html
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_cart_item_price($price_html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['_ak_is_composed_set'])) {
            $applied_price = isset($cart_item['_ak_applied_price']) ? (float)$cart_item['_ak_applied_price'] : 0.0;
            if ($applied_price <= 0 && isset($cart_item['_ak_set_id']) && isset($cart_item['_ak_selected_weekends']) && isset($cart_item['_ak_headcount'])) {
                $calc = \AK_Set\Pricing\Pricing_Engine::calculate((int)$cart_item['_ak_set_id'], $cart_item['_ak_selected_weekends'], (int)$cart_item['_ak_headcount']);
                if ($calc['valid'] && $calc['total_price'] > 0) {
                    $applied_price = (float)$calc['total_price'];
                }
            }

            if ($applied_price > 0 && function_exists('wc_price')) {
                return wc_price($applied_price);
            }
        }

        return $price_html;
    }

    /**
     * Filter cart line item subtotal display HTML
     *
     * @param string $subtotal_html
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['_ak_is_composed_set'])) {
            $applied_price = isset($cart_item['_ak_applied_price']) ? (float)$cart_item['_ak_applied_price'] : 0.0;
            if ($applied_price <= 0 && isset($cart_item['_ak_set_id']) && isset($cart_item['_ak_selected_weekends']) && isset($cart_item['_ak_headcount'])) {
                $calc = \AK_Set\Pricing\Pricing_Engine::calculate((int)$cart_item['_ak_set_id'], $cart_item['_ak_selected_weekends'], (int)$cart_item['_ak_headcount']);
                if ($calc['valid'] && $calc['total_price'] > 0) {
                    $applied_price = (float)$calc['total_price'];
                }
            }

            if ($applied_price > 0 && function_exists('wc_price')) {
                return wc_price($applied_price);
            }
        }

        return $subtotal_html;
    }
}
