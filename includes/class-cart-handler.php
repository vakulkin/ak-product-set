<?php

/**
 * Cart validation, dynamic pricing engine, and cart display filters.
 *
 * @package AK_Set
 */

declare(strict_types=1);

namespace AK_Set;

/**
 * Class Cart_Handler
 *
 * Responsibilities:
 *  1. Validate add-to-cart to prevent direct adds without participant data.
 *  2. Silently remove invalid items that survive a session reload.
 *  3. Apply the 3-dimensional tiered pricing algorithm on every cart
 *     recalculation (woocommerce_before_calculate_totals).
 *  4. Display participant data under the product name in the cart table.
 *  5. Lock the quantity field to 1 (read-only) for Set products.
 *  6. Show strikethrough regular price when a discount is applied.
 */
class Cart_Handler
{

	/**
	 * Computed prices for the current request, keyed by cart_item_key.
	 * Populated during calculate_prices() and consumed by display hooks.
	 *
	 * @var array<string, float>
	 */
	private array $computed_prices = [];

	public function __construct(private readonly Set_Validator $validator) {}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	public function register_hooks(): void
	{
		// Validation.
		add_filter('woocommerce_add_to_cart_validation',    [$this, 'validate_add_to_cart'],       10, 6);
		add_action('woocommerce_cart_loaded_from_session',  [$this, 'clean_invalid_session_items'], 10, 1);

		// Pricing engine.
		add_action('woocommerce_before_calculate_totals', [$this, 'calculate_prices'], 10, 1);

		// Cart display.
		add_filter('woocommerce_get_item_data',       [$this, 'display_participant_data'],   10, 2);
		add_filter('woocommerce_cart_item_quantity',  [$this, 'lock_cart_quantity'],          10, 3);
		add_filter('woocommerce_cart_item_price',     [$this, 'maybe_strikethrough_price'],   10, 3);
		add_filter('woocommerce_cart_item_subtotal',  [$this, 'maybe_strikethrough_subtotal'], 10, 3);
	}

	// -------------------------------------------------------------------------
	// 1. Validation
	// -------------------------------------------------------------------------

	/**
	 * Block adding a Set product to the cart without valid participant data.
	 *
	 * @param bool  $passed         Current validation state.
	 * @param int   $product_id     Product ID being added.
	 * @param int   $_quantity      Quantity (required by hook, unused here).
	 * @param int   $_variation_id  Variation ID (required by hook, unused here).
	 * @param array $_variations    Variation data (required by hook, unused here).
	 * @param array $cart_item_data Custom cart item data passed to add_to_cart().
	 * @return bool False if Set product is missing participant data.
	 */
	public function validate_add_to_cart(
		bool  $passed,
		int   $product_id,
		int   $_quantity,
		int   $_variation_id,
		array $_variations,
		array $cart_item_data
	): bool {
		if (! $this->validator->is_set_product($product_id)) {
			return $passed;
		}

		if ($msg = $this->validator->get_sales_block_message($product_id)) {
			wc_add_notice($msg, 'error');
			return false;
		}

		if (empty($cart_item_data['_ak_participant_data']['name'])) {
			wc_add_notice(
				__('Nie można dodać produktu bezpośrednio. Kliknij „Wybierz opcje" na stronie produktu, aby dodać uczestnika.', 'ak-product-set'),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * After the cart is restored from session, silently remove any Set items
	 * that are missing participant data (e.g. corrupted sessions).
	 *
	 * @param \WC_Cart $cart The cart object.
	 */
	public function clean_invalid_session_items(\WC_Cart $cart): void
	{
		$to_remove = [];

		foreach ($cart->cart_contents as $key => $item) {
			// Only care about Set products (keyed by _ak_set_id presence).
			if (empty($item['_ak_set_id'])) {
				continue;
			}
			if (empty($item['_ak_participant_data']['name'])) {
				$to_remove[] = $key;
			}
		}

		foreach ($to_remove as $key) {
			unset($cart->cart_contents[$key]);
		}
	}

	// -------------------------------------------------------------------------
	// 2. Pricing engine
	// -------------------------------------------------------------------------

	/**
	 * Main pricing engine. Runs on every cart recalculation.
	 *
	 * Algorithm (per set → per product):
	 *  1. Q_items = # unique product IDs from this Set in cart (min 1)
	 *  2. For each product: Q_people = # cart items for that product
	 *  3. group_tier: 'ind' (1-4), 'g5' (5-9), 'g10' (10+)
	 *  4. round: 1 if today ≤ round_1_end_date, 2 if ≤ round_2_end_date, else 3
	 *  5. Resolve unit_price with 3-level fallback chain
	 *  6. Apply via set_price() to each cart item for that product
	 *
	 * @param \WC_Cart $cart The cart object passed by the hook.
	 */
	public function calculate_prices(\WC_Cart $cart): void
	{
		if (is_admin() && ! defined('DOING_AJAX')) {
			return;
		}

		// Group cart items by set_id → product_id → [key => item].
		$sets_data = []; // [ set_id => [ product_id => [ key => item ] ] ]

		foreach ($cart->get_cart() as $key => $item) {
			$set_id = (int) ($item['_ak_set_id'] ?? 0);
			if (! $set_id) {
				continue;
			}
			$product_id = (int) $item['product_id'];

			$sets_data[$set_id]                         ??= [];
			$sets_data[$set_id][$product_id]          ??= [];
			$sets_data[$set_id][$product_id][$key]   = $item;
		}

		if (empty($sets_data)) {
			return;
		}

		$today = date('Ymd');

		foreach ($sets_data as $set_id => $products_in_set) {
			$q_items = max(1, count($products_in_set));

			// Calculate minimum participant count across all products (weekends) in this set.
			$min_people = null;
			foreach ($products_in_set as $product_id => $items_for_product) {
				$count = count($items_for_product);
				if ($min_people === null || $count < $min_people) {
					$min_people = $count;
				}
			}

			if ($min_people === null || $min_people === 0) {
				continue;
			}

			$group_tier   = $this->resolve_group_tier($min_people);
			$round        = $this->validator->determine_round($set_id, $today);

			[$unit_price, $is_package, $matched_rule] = $this->validator->resolve_price(
				$set_id,
				$q_items,
				$round,
				$group_tier
			);

			if ($unit_price <= 0.0) {
				// Fallback: let WooCommerce keep the default price.
				continue;
			}

			foreach ($products_in_set as $product_id => $items_for_product) {
				foreach ($items_for_product as $key => $item) {
					// set_price() modifies the WC_Product object for this request.
					$item['data']->set_price($unit_price);

					// Persist applied price to cart session so Order_Handler can read it.
					$cart->cart_contents[$key]['_ak_applied_price'] = $unit_price;
					$cart->cart_contents[$key]['_ak_price_rule']    = $matched_rule;

					// Cache for display filters.
					$this->computed_prices[$key] = $unit_price;
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// 3. Cart display
	// -------------------------------------------------------------------------

	/**
	 * Display participant name, T-shirt size, and cut under the product name
	 * in the cart table via woocommerce_get_item_data.
	 *
	 * @param array $item_data Current meta rows.
	 * @param array $cart_item The cart item.
	 * @return array Modified meta rows.
	 */
	public function display_participant_data(array $item_data, array $cart_item): array
	{
		if (! isset($cart_item['_ak_participant_data'])) {
			return $item_data;
		}

		$data = $cart_item['_ak_participant_data'];

		if (! empty($data['name'])) {
			$item_data[] = [
				'key'   => __('Uczestnik', 'ak-product-set'),
				'value' => esc_html($data['name']),
			];
		}
		if (! empty($data['size'])) {
			$item_data[] = [
				'key'   => __('Rozmiar', 'ak-product-set'),
				'value' => esc_html($data['size']),
			];
		}
		if (! empty($data['cut'])) {
			$item_data[] = [
				'key'   => __('Krój', 'ak-product-set'),
				'value' => esc_html($data['cut']),
			];
		}

		if (! empty($cart_item['_ak_price_rule']) && current_user_can('manage_options')) {
			$item_data[] = [
				'key'   => __('Reguła Cenowa (Debug)', 'ak-product-set'),
				'value' => esc_html($cart_item['_ak_price_rule']),
			];
		}

		return $item_data;
	}

	/**
	 * Replace the quantity input with a static <span>1</span> for Set products.
	 * Includes a hidden input so WooCommerce's cart update form doesn't error.
	 *
	 * @param string $quantity_html Current quantity HTML.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $cart_item     Cart item data.
	 * @return string Modified quantity HTML.
	 */
	public function lock_cart_quantity(string $quantity_html, string $cart_item_key, array $cart_item): string
	{
		if (empty($cart_item['_ak_set_id'])) {
			return $quantity_html;
		}

		return sprintf(
			'<span class="ak-qty-locked">1</span>'
				. '<input type="hidden" name="cart[%s][qty]" value="1">',
			esc_attr($cart_item_key)
		);
	}

	/**
	 * Show strikethrough regular price when a tier discount is in effect.
	 *
	 * @param string $price_html    Original price HTML.
	 * @param array  $cart_item     Cart item.
	 * @param string $_cart_item_key Cart key (required by hook, unused here).
	 * @return string Modified price HTML.
	 */
	public function maybe_strikethrough_price(string $price_html, array $cart_item, string $_cart_item_key): string
	{
		if (empty($cart_item['_ak_set_id'])) {
			return $price_html;
		}

		$regular = (float) $cart_item['data']->get_regular_price();
		$current = (float) $cart_item['data']->get_price();

		if ($regular > 0 && $current > 0 && ($regular - $current) > 0.01) {
			return '<del aria-label="' . esc_attr__('Cena regularna', 'ak-product-set') . '">'
				. wc_price($regular)
				. '</del> <ins>'
				. wc_price($current)
				. '</ins>';
		}

		return $price_html;
	}

	/**
	 * Show strikethrough subtotal when a tier discount is in effect.
	 *
	 * @param string $subtotal      Original subtotal HTML.
	 * @param array  $cart_item     Cart item.
	 * @param string $_cart_item_key Cart key (required by hook, unused here).
	 * @return string Modified subtotal HTML.
	 */
	public function maybe_strikethrough_subtotal(string $subtotal, array $cart_item, string $_cart_item_key): string
	{
		if (empty($cart_item['_ak_set_id'])) {
			return $subtotal;
		}

		$regular = (float) $cart_item['data']->get_regular_price();
		$current = (float) $cart_item['data']->get_price();
		$qty     = (int) $cart_item['quantity'];

		if ($regular > 0 && $current > 0 && ($regular - $current) > 0.01) {
			return '<del>' . wc_price($regular * $qty) . '</del> <ins>' . wc_price($current * $qty) . '</ins>';
		}

		return $subtotal;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Determine the group tier string based on headcount.
	 *
	 * @param int $q_people Number of people (cart items for this product).
	 * @return string 'ind' | 'g5' | 'g10'
	 */
	private function resolve_group_tier(int $q_people): string
	{
		if ($q_people >= 10) {
			return 'g10';
		}
		if ($q_people >= 5) {
			return 'g5';
		}
		return 'ind';
	}
}
