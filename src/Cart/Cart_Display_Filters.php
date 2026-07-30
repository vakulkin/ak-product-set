<?php

namespace AK_Set\Cart;

use AK_Set\Models\Set_Model;
use AK_Set\Models\Weekend_Model;
use AK_Set\Pricing\Pricing_Engine;

if (!defined('ABSPATH')) {
    exit;
}

class Cart_Display_Filters
{

    public function init(): void
    {
        add_filter('woocommerce_cart_item_name', [$this, 'filter_cart_item_name'], 10, 3);
        add_filter('woocommerce_cart_item_permalink', [$this, 'filter_cart_item_permalink'], 10, 3);
        add_filter('woocommerce_get_item_data', [$this, 'filter_item_data'], 10, 2);
        add_filter('woocommerce_cart_item_quantity', [$this, 'filter_cart_item_quantity'], 10, 3);
        add_filter('woocommerce_widget_cart_item_quantity', [$this, 'filter_widget_cart_item_quantity'], 10, 3);
        add_filter('woocommerce_cart_item_price', [$this, 'filter_cart_item_price'], 10, 3);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'filter_cart_item_subtotal'], 10, 3);
        // Quantity locking: prevent any composed set item going above qty=1
        add_filter('woocommerce_quantity_input_max', [$this, 'filter_quantity_input_max'], 10, 2);
        add_filter('woocommerce_update_cart_validation', [$this, 'validate_cart_update_quantity'], 10, 4);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the display price for a composed set cart item.
     *
     * Priority order:
     *   1. Price already set on the WC_Product object (from inject_prices_from_session)
     *   2. Stored _ak_applied_price meta value
     *   3. Live Pricing Engine calculation (fallback, e.g. after session expiry)
     *
     * @param array $cart_item
     * @return float 0.0 if price cannot be determined
     */
    private function resolve_display_price(array $cart_item): float
    {
        // 1. Product object price (most up-to-date, set by inject_prices_from_session)
        if (!empty($cart_item['data']) && $cart_item['data'] instanceof \WC_Product) {
            $price = (float) $cart_item['data']->get_price();
            if ($price > 0) {
                return $price;
            }
        }

        // 2. Stored applied price in cart meta
        $applied = isset($cart_item['_ak_applied_price']) ? (float) $cart_item['_ak_applied_price'] : 0.0;
        if ($applied > 0) {
            return $applied;
        }

        // 3. Live calculation fallback
        if (isset($cart_item['_ak_set_id'], $cart_item['_ak_selected_weekends'], $cart_item['_ak_headcount'])) {
            $calc = Pricing_Engine::calculate(
                (int) $cart_item['_ak_set_id'],
                $cart_item['_ak_selected_weekends'],
                (int) $cart_item['_ak_headcount']
            );
            if ($calc['valid'] && $calc['total_price'] > 0) {
                return (float) $calc['total_price'];
            }
        }

        return 0.0;
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Filter cart item permalink to point to the Set CPT post page.
     * Ensures product image, title, and mini-cart links lead to the Set page.
     *
     * @param string $permalink
     * @param array  $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_cart_item_permalink($permalink, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['_ak_is_composed_set'])) {
            $set_id = isset($cart_item['_ak_set_id']) ? (int) $cart_item['_ak_set_id'] : 0;
            if ($set_id > 0) {
                $set_permalink = get_permalink($set_id);
                if (!empty($set_permalink)) {
                    return $set_permalink;
                }
            }
        }

        return $permalink;
    }

    /**
     * Customize composed line item title in cart & checkout table, wrapping title in link to set page
     *
     * @param string $name
     * @param array  $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_cart_item_name($name, $cart_item, $cart_item_key)
    {
        if (empty($cart_item['_ak_is_composed_set'])) {
            return $name;
        }

        $set_id     = isset($cart_item['_ak_set_id']) ? (int) $cart_item['_ak_set_id'] : 0;
        $set        = new Set_Model($set_id);
        $title_text = $set->get_title() ?: $name;
        $permalink  = get_permalink($set_id);

        if (empty($permalink)) {
            return esc_html($title_text);
        }

        return sprintf(
            '<a href="%1$s" class="ak-set-title-link">%2$s</a> <a href="%1$s" class="ak-edit-booking-link">(%3$s)</a>',
            esc_url($permalink),
            esc_html($title_text),
            esc_html__('Edytuj rezerwację', 'ak-product-set')
        );
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
        $headcount         = isset($cart_item['_ak_headcount']) ? (int) $cart_item['_ak_headcount'] : 1;
        $participants      = isset($cart_item['_ak_participants']) ? $cart_item['_ak_participants'] : [];

        // 1. Wybrane Weekendy (Individual key entries: Termin 1, Termin 2, etc.)
        $i = 1;
        foreach ($selected_weekends as $wid) {
            $w = new Weekend_Model($wid);
            if ($w->get_wc_product()) {
                $item_data[] = [
                    'name'  => sprintf(__('Termin %d', 'ak-product-set'), $i),
                    'value' => esc_html($w->get_title()),
                ];
                $i++;
            }
        }

        // 2. Liczba uczestników
        $item_data[] = [
            'name'  => __('Liczba uczestników', 'ak-product-set'),
            'value' => sprintf(__('%d os.', 'ak-product-set'), $headcount),
        ];

        // 3. Uczestnicy
        if (!empty($participants)) {
            $participant_rows = [];

            foreach ($participants as $p) {
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
                    $cut             = (!empty($p['tshirt_cut']) && $p['tshirt_cut'] === 'women')
                        ? __('damska', 'ak-product-set')
                        : __('męska', 'ak-product-set');
                    $details_parts[] = sprintf(
                        __('Koszulka: %1$s (%2$s)', 'ak-product-set'),
                        esc_html($p['tshirt_size']),
                        $cut
                    );
                }

                $meta_line = !empty($details_parts)
                    ? '<br><span style="font-size:12px; color:#52525b;">' . implode(' • ', $details_parts) . '</span>'
                    : '';

                $participant_rows[] = '• <strong>' . esc_html($p['name']) . '</strong>' . $meta_line;
            }

            if (!empty($participant_rows)) {
                $item_data[] = [
                    'name'  => __('Uczestnicy', 'ak-product-set'),
                    'value' => implode('<br>', $participant_rows),
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
     * @param array  $cart_item
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
     * Filter mini-cart widget quantity+price display string
     *
     * @param string $html
     * @param array  $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_widget_cart_item_quantity($html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['_ak_is_composed_set'])) {
            $price = $this->resolve_display_price($cart_item);
            if ($price > 0 && function_exists('wc_price')) {
                return '<span class="quantity">1 &times; ' . wc_price($price) . '</span>';
            }
        }

        return $html;
    }

    /**
     * Filter cart line item unit price display HTML
     *
     * @param string $price_html
     * @param array  $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_cart_item_price($price_html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['_ak_is_composed_set'])) {
            $price = $this->resolve_display_price($cart_item);
            if ($price > 0 && function_exists('wc_price')) {
                return wc_price($price);
            }
        }

        return $price_html;
    }

    /**
     * Filter cart line item subtotal display HTML
     *
     * @param string $subtotal_html
     * @param array  $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key)
    {
        if (!empty($cart_item['_ak_is_composed_set'])) {
            $price = $this->resolve_display_price($cart_item);
            if ($price > 0 && function_exists('wc_price')) {
                return wc_price($price);
            }
        }

        return $subtotal_html;
    }

    /**
     * Cap the quantity input max attribute to 1 for composed set items.
     * Renders max="1" on the HTML <input type="number"> in the cart page.
     *
     * @param float|int   $max
     * @param \WC_Product $product
     * @return float|int
     */
    /**
     * Cap the quantity input max attribute to 1 ONLY for composed set products.
     * Regular products in the store are completely unaffected.
     *
     * @param float|int   $max
     * @param \WC_Product $product
     * @return float|int
     */
    public function filter_quantity_input_max($max, $product)
    {
        if (!($product instanceof \WC_Product)) {
            return $max;
        }

        $universal_id = Composed_Cart_Manager::get_or_create_universal_product_id();
        if ($universal_id > 0 && $product->get_id() === $universal_id) {
            return 1;
        }

        return $max;
    }


    /**
     * Intercept cart quantity updates and silently clamp composed set item qty back to 1.
     * Prevents users from typing "2" in the cart page qty field.
     *
     * @param bool   $valid
     * @param string $cart_item_key
     * @param array  $cart_item
     * @param int    $quantity
     * @return bool
     */
    public function validate_cart_update_quantity($valid, $cart_item_key, $cart_item, $quantity)
    {
        if (!empty($cart_item['_ak_is_composed_set']) && (int) $quantity > 1) {
            WC()->cart->set_quantity($cart_item_key, 1, false);
            return false;
        }

        return $valid;
    }
}
