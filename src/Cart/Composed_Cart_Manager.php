<?php

namespace AK_Set\Cart;

use AK_Set\Pricing\Pricing_Engine;
use AK_Set\Models\Participant_Model;
use AK_Set\Models\Set_Model;
use AK_Set\Models\Weekend_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Composed_Cart_Manager {

    public function init(): void {
        add_action('woocommerce_before_calculate_totals', [$this, 'override_cart_item_prices'], 20, 1);
        add_filter('woocommerce_cart_item_product', [$this, 'filter_cart_item_product'], 20, 2);
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart_stock']);
        add_action('woocommerce_checkout_process', [$this, 'validate_cart_stock']);
    }

    /**
     * Return the active WC_Cart instance, or null if not available.
     * Central guard used by all methods that interact with the cart.
     *
     * @return \WC_Cart|null
     */
    private static function get_cart(): ?\WC_Cart {
        if (!function_exists('WC') || !WC() || !WC()->cart) {
            return null;
        }
        return WC()->cart;
    }

    /**
     * Get or programmatically create the Universal Master Product (only once)
     * SKU: ak-set-universal-product
     *
     * @return int
     */
    public static function get_or_create_universal_product_id() {
        $option_key = 'ak_set_universal_product_id';
        $product_id = get_option($option_key);

        if ($product_id && get_post_type($product_id) === 'product') {
            $status = get_post_status($product_id);
            if ($status === 'publish') {
                return (int)$product_id;
            }
            // If post was trashed or put in draft, restore it to published
            wp_update_post([
                'ID'          => $product_id,
                'post_status' => 'publish',
            ]);
            return (int)$product_id;
        }

        // Search by SKU if option is lost
        if (function_exists('wc_get_product_id_by_sku')) {
            $existing_id = wc_get_product_id_by_sku('ak-set-universal-product');
            if ($existing_id) {
                wp_update_post([
                    'ID'          => $existing_id,
                    'post_status' => 'publish',
                ]);
                update_option($option_key, $existing_id);
                return (int)$existing_id;
            }
        }

        // Create universal hidden virtual simple product programmatically
        if (!class_exists('\WC_Product_Simple')) {
            return 0;
        }

        $product = new \WC_Product_Simple();
        $product->set_name(__('Rezerwacja Zestawu AK', 'ak-product-set'));
        $product->set_slug('ak-set-universal-booking');
        $product->set_sku('ak-set-universal-product');
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_virtual(true);
        $product->set_price(0);
        $product->set_regular_price(0);
        $product->set_manage_stock(false);
        $product->set_sold_individually(false);
        $new_id = $product->save();

        if ($new_id) {
            update_option($option_key, $new_id);
            return (int)$new_id;
        }

        return 0;
    }

    /**
     * Server-side real-time cart stock validation
     * Blocks checkout and displays notice if cumulative stock dropped below requested headcount
     */
    public function validate_cart_stock(): void {
        $cart = self::get_cart();
        if (!$cart) {
            return;
        }

        $cart_changed = false;

        // Calculate cumulative requested headcount per weekend across all composed set cart items
        $weekend_total_headcount = [];
        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['_ak_is_composed_set'])) {
                continue;
            }
            $selected = isset($cart_item['_ak_selected_weekends']) ? $cart_item['_ak_selected_weekends'] : [];
            $hc = isset($cart_item['_ak_headcount']) ? (int)$cart_item['_ak_headcount'] : 1;
            foreach ($selected as $wid) {
                $wid = (int)$wid;
                if (!isset($weekend_total_headcount[$wid])) {
                    $weekend_total_headcount[$wid] = 0;
                }
                $weekend_total_headcount[$wid] += $hc;
            }
        }

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['_ak_is_composed_set'])) {
                continue;
            }

            $set_id = isset($cart_item['_ak_set_id']) ? (int)$cart_item['_ak_set_id'] : 0;
            $selected_weekends = isset($cart_item['_ak_selected_weekends']) ? $cart_item['_ak_selected_weekends'] : [];
            $headcount = isset($cart_item['_ak_headcount']) ? (int)$cart_item['_ak_headcount'] : 1;

            if (empty($selected_weekends) || $headcount < 1) {
                $cart->remove_cart_item($cart_item_key);
                $cart_changed = true;
                continue;
            }

            $set = new Set_Model($set_id);
            if (!$set->exists() || get_post_status($set_id) !== 'publish') {
                $cart->remove_cart_item($cart_item_key);
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('Zestaw został usunięty z koszyka, ponieważ nie jest już dostępny.', 'ak-product-set'), 'error');
                }
                $cart_changed = true;
                continue;
            }

            $calc = Pricing_Engine::calculate($set_id, $selected_weekends, $headcount);
            if (!$calc['valid'] || (isset($calc['total_price']) && $calc['total_price'] <= 0)) {
                $cart->remove_cart_item($cart_item_key);
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('Zestaw został usunięty z koszyka, ponieważ jego cena wynosi 0 zł lub jest nieprawidłowa.', 'ak-product-set'), 'error');
                }
                $cart_changed = true;
                continue;
            }

            $original_count = count($selected_weekends);
            $valid_weekends = [];

            // Check real-time stock limits and expiration on server side for each selected weekend
            foreach ($selected_weekends as $wid) {
                $w = new Weekend_Model($wid);
                $is_invalid = false;
                $reason = '';

                $wc_product = $w->get_wc_product();

                if (!$wc_product || $wc_product->get_status() !== 'publish') {
                    $is_invalid = true;
                    $reason = __('produkt już nie istnieje lub jest niedostępny', 'ak-product-set');
                } elseif (!$wc_product->is_purchasable()) {
                    $is_invalid = true;
                    $reason = __('produkt nie jest dostępny do zakupu', 'ak-product-set');
                } elseif (!$wc_product->is_in_stock()) {
                    $is_invalid = true;
                    $reason = __('produkt jest wyprzedany', 'ak-product-set');
                } elseif ($w->is_expired()) {
                    $is_invalid = true;
                    $reason = __('zakończono rekrutację', 'ak-product-set');
                } elseif ($w->managing_stock()) {
                    $stock = $w->get_stock_quantity();
                    $total_in_cart = isset($weekend_total_headcount[$wid]) ? $weekend_total_headcount[$wid] : $headcount;
                    if ($stock !== null && $total_in_cart > $stock) {
                        $is_invalid = true;
                        $reason = sprintf(__('brak wystarczającej liczby miejsc (łącznie w koszyku: %1$d os., dostępne: %2$d os.)', 'ak-product-set'), $total_in_cart, $stock);
                    }
                }

                if ($is_invalid) {
                    $title = $w->get_title() ? $w->get_title() : __('Nieznany termin', 'ak-product-set');
                    $message = sprintf(
                        __('Termin "%1$s" z zestawu "%2$s" został automatycznie usunięty z koszyka (%3$s).', 'ak-product-set'),
                        esc_html($title),
                        esc_html($set->get_title()),
                        $reason
                    );
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice($message, 'error');
                    }
                    $cart_changed = true;
                } else {
                    $valid_weekends[] = $wid;
                }
            }

            if (count($valid_weekends) !== $original_count) {
                if (empty($valid_weekends)) {
                    $cart->remove_cart_item($cart_item_key);
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(__('Zestaw został usunięty z koszyka, ponieważ żaden z wybranych terminów nie jest już dostępny.', 'ak-product-set'), 'error');
                    }
                } else {
                    // Update cart item with only the valid weekends
                    $cart->cart_contents[$cart_item_key]['_ak_selected_weekends'] = $valid_weekends;
                }
            }
        }

        if ($cart_changed) {
            $cart->set_session();
        }
    }

    /**
     * Add composed set line item in WooCommerce cart using Universal Product
     *
     * @param int $set_id
     * @param array $selected_weekends
     * @param int $headcount
     * @param array $participants_raw
     * @return string|false Cart item key on success, false on failure
     */
    public function add_set_to_cart($set_id, array $selected_weekends, $headcount, array $participants_raw) {
        $cart = self::get_cart();
        if (!$cart) {
            return false;
        }

        $calc = Pricing_Engine::calculate($set_id, $selected_weekends, $headcount);
        if (!$calc['valid']) {
            throw new \Exception(isset($calc['error']) ? $calc['error'] : __('Błąd wyliczenia ceny.', 'ak-product-set'));
        }

        if (!empty($calc['stock_clamped'])) {
            throw new \Exception(__('Wybrano więcej miejsc niż jest aktualnie dostępne w wybranych terminach.', 'ak-product-set'));
        }

        // Calculate cumulative headcount for selected weekends in OTHER sets already in cart
        $cart_weekend_totals = [];
        foreach ($cart->get_cart() as $existing_item) {
            if (empty($existing_item['_ak_is_composed_set'])) {
                continue;
            }
            // Ignore previous bookings for the SAME set ID, as it will be overwritten
            if (isset($existing_item['_ak_set_id']) && (int)$existing_item['_ak_set_id'] === (int)$set_id) {
                continue;
            }
            $ex_weekends = isset($existing_item['_ak_selected_weekends']) ? $existing_item['_ak_selected_weekends'] : [];
            $ex_hc = isset($existing_item['_ak_headcount']) ? (int)$existing_item['_ak_headcount'] : 1;
            foreach ($ex_weekends as $wid) {
                $wid = (int)$wid;
                if (!isset($cart_weekend_totals[$wid])) {
                    $cart_weekend_totals[$wid] = 0;
                }
                $cart_weekend_totals[$wid] += $ex_hc;
            }
        }

        // Validate that adding new set headcount doesn't exceed stock limit for any selected weekend
        foreach ($selected_weekends as $wid) {
            $wid = (int)$wid;
            $w = new Weekend_Model($wid);
            if ($w->managing_stock()) {
                $stock = $w->get_stock_quantity();
                $already_in_cart = isset($cart_weekend_totals[$wid]) ? $cart_weekend_totals[$wid] : 0;
                $total_requested = $already_in_cart + (int)$headcount;
                if ($stock !== null && $total_requested > $stock) {
                    $title = $w->get_title() ? $w->get_title() : __('Wybrany termin', 'ak-product-set');
                    throw new \Exception(sprintf(
                        __('Brak wystarczającej liczby miejsc dla terminu "%1$s". Dostępne miejsca: %2$d os. (w koszyku masz już %3$d os., próbujesz dodać %4$d os.).', 'ak-product-set'),
                        esc_html($title),
                        $stock,
                        $already_in_cart,
                        (int)$headcount
                    ));
                }
            }
        }

        // Overwrite / replace any previous booking for this SAME set ID
        $this->clear_existing_set_cart_items($set_id);

        $participants = Participant_Model::from_collection($participants_raw);
        $participants_array = array_map(function ($p) {
            return $p->to_array();
        }, $participants);

        // Get universal master product ID (created once)
        $universal_product_id = self::get_or_create_universal_product_id();
        if (!$universal_product_id) {
            // Fallback to first selected weekend product if universal product creation failed
            $universal_product_id = (int)$selected_weekends[0];
        }

        $cart_item_data = [
            '_ak_is_composed_set'   => true,
            '_ak_set_id'            => (int)$set_id,
            '_ak_selected_weekends' => array_map('intval', $selected_weekends),
            '_ak_headcount'         => (int)$headcount,
            '_ak_participants'      => $participants_array,
            '_ak_applied_price'     => (float)$calc['total_price'],
            '_ak_per_person_price'  => (float)$calc['per_person_price'],
            '_ak_round'             => (int)$calc['round'],
            '_ak_tier'              => (string)$calc['tier'],
            // Unique key ensures WooCommerce creates a fresh cart slot without key collision
            '_ak_unique_key'        => md5(uniqid('ak_set_' . (int)$set_id . '_', true)),
        ];

        return $cart->add_to_cart(
            $universal_product_id,
            1, // Composed single line item has quantity 1
            0,
            [],
            $cart_item_data
        );
    }

    /**
     * Clear previous AK Set composed items from cart.
     * If $set_id is provided, only removes items for that specific set ID (overwriting same set).
     * If $set_id is null, removes all composed set items from cart.
     *
     * @param int|null $set_id
     */
    public function clear_existing_set_cart_items($set_id = null): void {
        $cart = self::get_cart();
        if (!$cart) {
            return;
        }

        $keys_to_remove = [];
        foreach ($cart->get_cart() as $key => $item) {
            if (!empty($item['_ak_is_composed_set'])) {
                if ($set_id === null || (isset($item['_ak_set_id']) && (int) $item['_ak_set_id'] === (int) $set_id)) {
                    $keys_to_remove[] = $key;
                }
            }
        }

        foreach ($keys_to_remove as $key) {
            $cart->remove_cart_item($key);
        }
    }

    /**
     * Override cart item price dynamically via woocommerce_before_calculate_totals
     *
     * @param \WC_Cart $cart
     */
    public function override_cart_item_prices($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (did_action('woocommerce_before_calculate_totals') > 1) {
            return;
        }

        // Deduplicate: If multiple items exist for the SAME set_id, keep ONLY the last added one
        $set_latest_keys = [];
        $keys_to_remove  = [];
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['_ak_is_composed_set'])) {
                continue;
            }
            $set_id = isset($cart_item['_ak_set_id']) ? (int)$cart_item['_ak_set_id'] : 0;
            if ($set_id > 0) {
                if (isset($set_latest_keys[$set_id])) {
                    $keys_to_remove[] = $set_latest_keys[$set_id];
                }
                $set_latest_keys[$set_id] = $cart_item_key;
            }
        }

        foreach ($keys_to_remove as $old_key) {
            $cart->remove_cart_item($old_key);
        }

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['_ak_is_composed_set'])) {
                continue;
            }

            $set_id = isset($cart_item['_ak_set_id']) ? $cart_item['_ak_set_id'] : 0;
            $set = new Set_Model($set_id);
            if ($set->get_title()) {
                $cart_item['data']->set_name($set->get_title());
            }

            $selected_weekends = isset($cart_item['_ak_selected_weekends']) ? $cart_item['_ak_selected_weekends'] : [];
            $headcount = isset($cart_item['_ak_headcount']) ? $cart_item['_ak_headcount'] : 1;

            $calc = Pricing_Engine::calculate($set_id, $selected_weekends, $headcount);
            if ($calc['valid'] && $calc['total_price'] > 0) {
                $price = (float)$calc['total_price'];
                $cart_item['data']->set_price($price);
                $cart_item['data']->set_regular_price($price);
            } else {
                $cart->remove_cart_item($cart_item_key);
            }
        }
    }

    /**
     * Dynamically inject applied set price and set name into WC_Product instance on cart item retrieval.
     * Standard WooCommerce filter for cart product object modification.
     *
     * @param \WC_Product $product
     * @param array       $cart_item
     * @return \WC_Product
     */
    public function filter_cart_item_product($product, $cart_item) {
        if (!empty($cart_item['_ak_is_composed_set']) && $product instanceof \WC_Product) {
            $applied_price = isset($cart_item['_ak_applied_price']) ? (float) $cart_item['_ak_applied_price'] : 0.0;
            if ($applied_price <= 0 && isset($cart_item['_ak_set_id'], $cart_item['_ak_selected_weekends'], $cart_item['_ak_headcount'])) {
                $calc = Pricing_Engine::calculate(
                    (int) $cart_item['_ak_set_id'],
                    $cart_item['_ak_selected_weekends'],
                    (int) $cart_item['_ak_headcount']
                );
                if ($calc['valid'] && $calc['total_price'] > 0) {
                    $applied_price = (float) $calc['total_price'];
                }
            }

            if ($applied_price > 0) {
                $product->set_price($applied_price);
                $product->set_regular_price($applied_price);
            }

            $set_id = isset($cart_item['_ak_set_id']) ? (int) $cart_item['_ak_set_id'] : 0;
            if ($set_id > 0) {
                $set   = new Set_Model($set_id);
                $title = $set->get_title();
                if ($title) {
                    $product->set_name($title);
                }
            }
        }

        return $product;
    }
}
