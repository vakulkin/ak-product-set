<?php
/**
 * Shared utility methods for determining Set membership and T-shirt requirements.
 *
 * @package AK_Set
 */

declare(strict_types=1);

namespace AK_Set;

/**
 * Class Set_Validator
 *
 * Provides helpers used by both the Participant_Form and Cart_Handler to check
 * whether a product belongs to an ak_set post, and to query live cart data.
 */
class Set_Validator {

	/**
	 * In-memory cache: product_id → array of set IDs.
	 *
	 * @var array<int, int[]>
	 */
	private array $product_set_cache = [];

	/**
	 * In-memory cache: list of all product IDs in any set.
	 *
	 * @var int[]|null
	 */
	private ?array $all_set_product_ids_cache = null;

	// -------------------------------------------------------------------------
	// Set membership
	// -------------------------------------------------------------------------

	/**
	 * Return all product IDs that belong to any published ak_set.
	 * Results are cached per request.
	 *
	 * @return int[]
	 */
	public function get_all_set_product_ids(): array
	{
		if ($this->all_set_product_ids_cache !== null) {
			return $this->all_set_product_ids_cache;
		}

		$set_ids = get_posts([
			'post_type'      => 'ak_set',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]);

		$product_ids = [];
		foreach ($set_ids as $set_id) {
			$products = get_field('set_products', (int) $set_id) ?: [];
			foreach ((array) $products as $pid) {
				$pid_int = (int) $pid;
				if ($pid_int > 0) {
					$product_ids[] = $pid_int;
				}
			}
		}

		$this->all_set_product_ids_cache = array_values(array_unique($product_ids));
		return $this->all_set_product_ids_cache;
	}

	/**
	 * Return all ak_set post IDs that include the given product.
	 *
	 * Uses get_field() so it works with any ACF storage format.
	 * Results are cached per request.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return int[] Array of ak_set post IDs.
	 */
	public function get_sets_for_product( int $product_id ): array {
		if ( isset( $this->product_set_cache[ $product_id ] ) ) {
			return $this->product_set_cache[ $product_id ];
		}

		$set_ids = get_posts( [
			'post_type'      => 'ak_set',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$result = [];
		foreach ( $set_ids as $set_id ) {
			$products = get_field( 'set_products', (int) $set_id ) ?: [];
			// ACF relationship with return_format=id returns int[] or string[].
			if ( in_array( $product_id, array_map( 'intval', (array) $products ), true ) ) {
				$result[] = (int) $set_id;
			}
		}

		$this->product_set_cache[ $product_id ] = $result;
		return $result;
	}

	/**
	 * Return true if the product belongs to at least one ak_set.
	 *
	 * @param int $product_id WooCommerce product ID.
	 */
	public function is_set_product( int $product_id ): bool {
		return ! empty( $this->get_sets_for_product( $product_id ) );
	}

	/**
	 * Return the first (primary) ak_set ID for a product, or null.
	 *
	 * @param int $product_id WooCommerce product ID.
	 */
	public function get_primary_set( int $product_id ): ?int {
		$sets = $this->get_sets_for_product( $product_id );
		return $sets[0] ?? null;
	}

	// -------------------------------------------------------------------------
	// T-shirt flag
	// -------------------------------------------------------------------------

	/**
	 * Whether a specific ak_set requires T-shirt data.
	 *
	 * @param int $set_id ak_set post ID.
	 */
	public function set_has_tshirt( int $set_id ): bool {
		return (bool) get_field( 'set_has_tshirt', $set_id );
	}

	/**
	 * Whether ANY parent set for this product requires T-shirt data.
	 *
	 * @param int $product_id WooCommerce product ID.
	 */
	public function product_has_tshirt( int $product_id ): bool {
		foreach ( $this->get_sets_for_product( $product_id ) as $set_id ) {
			if ( $this->set_has_tshirt( $set_id ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Event Date Blocking
	// -------------------------------------------------------------------------

	/**
	 * Check if sales are blocked for a specific product (weekend) and return the reason.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return string|null Block message, or null if sales are active.
	 */
	public function get_sales_block_message( int $product_id ): ?string {
		$now = current_time('Y-m-d H:i:s');

		// 1. Check if sales have already ended
		$end_datetime = (string) get_field( 'ak_recruitment_end_datetime', $product_id );
		if ( ! $end_datetime ) {
			// Fallback to old field
			$old_end = (string) get_field( 'ak_sales_end_date', $product_id );
			if ( $old_end ) {
				$end_datetime = $old_end . ' 23:59:59';
			}
		}
		if ( $end_datetime && $now > $end_datetime ) {
			return __( 'Sprzedaż na to wydarzenie została zakończona.', 'ak-product-set' );
		}

		// 2. Check if sales haven't started yet
		$start_datetime = (string) get_field( 'ak_recruitment_start_datetime', $product_id );
		if ( $start_datetime && $now < $start_datetime ) {
			return __( 'Sprzedaż na to wydarzenie jeszcze się nie rozpoczęła.', 'ak-product-set' );
		}



		return null;
	}

	// -------------------------------------------------------------------------
	// Cart helpers
	// -------------------------------------------------------------------------

	/**
	 * Return all cart items that belong to a given set, grouped by product_id.
	 *
	 * Structure: [ product_id => [ cart_item_key => cart_item, … ] ]
	 *
	 * @param int $set_id ak_set post ID.
	 * @return array<int, array<string, mixed[]>>
	 */
	public function get_cart_set_data( int $set_id ): array {
		$result = [];

		if ( ! WC()->cart ) {
			return $result;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( (int) ( $item['_ak_set_id'] ?? 0 ) !== $set_id ) {
				continue;
			}
			$product_id = (int) $item['product_id'];
			if ( ! isset( $result[ $product_id ] ) ) {
				$result[ $product_id ] = [];
			}
			$result[ $product_id ][ $key ] = $item;
		}

		return $result;
	}

	/**
	 * Collect all unique set IDs that appear in the current cart.
	 *
	 * @return int[]
	 */
	public function get_sets_in_cart(): array {
		if ( ! WC()->cart ) {
			return [];
		}
		$set_ids = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			$set_id = (int) ( $item['_ak_set_id'] ?? 0 );
			if ( $set_id ) {
				$set_ids[ $set_id ] = $set_id;
			}
		}
		return array_values( $set_ids );
	}

	// -------------------------------------------------------------------------
	// Pricing & Round resolution
	// -------------------------------------------------------------------------

	/**
	 * Determine the maximum valid round defined for a set.
	 * If an end date for a round is not set, subsequent rounds do not exist.
	 *
	 * @param int $set_id Set post ID.
	 * @return int 1, 2, or 3
	 */
	public function get_max_round(int $set_id): int
	{
		$r1_end = (string) (get_field('round_1_end_date', $set_id) ?: '');
		$r2_end = (string) (get_field('round_2_end_date', $set_id) ?: '');

		if (! $r1_end) {
			return 1;
		}

		if (! $r2_end) {
			return 2;
		}

		return 3;
	}

	/**
	 * Determine the current pricing round (1, 2, or 3) for a given set.
	 *
	 * @param int         $set_id Set post ID.
	 * @param string|null $today  Optional today in Ymd format. Defaults to current date.
	 * @return int 1, 2, or 3
	 */
	public function determine_round(int $set_id, ?string $today = null): int
	{
		$today  = $today ?: date('Ymd');
		$r1_end = (string) (get_field('round_1_end_date', $set_id) ?: '');
		$r2_end = (string) (get_field('round_2_end_date', $set_id) ?: '');

		if (! $r1_end || $today <= $r1_end) {
			return 1;
		}

		if (! $r2_end || $today <= $r2_end) {
			return 2;
		}

		return 3;
	}

	/**
	 * Look up a price field starting at $round and stepping backwards to round 1.
	 * Returns the first non-zero value found, or [0.0, ''].
	 *
	 * @param int    $set_id Set ID.
	 * @param string $base   Field name prefix (e.g. 'price_2el_round').
	 * @param string $tier   Group tier suffix ('ind', 'g5', 'g10').
	 * @param int    $round  Starting round to try first (cascades down to 1).
	 * @return array{0: float, 1: string} First non-zero price found and its field name, or [0.0, ''].
	 */
	public function get_price_cascade(int $set_id, string $base, string $tier, int $round): array
	{
		for ($r = $round; $r >= 1; $r--) {
			$field_name = "{$base}{$r}_{$tier}";
			$raw = (float) (get_field($field_name, $set_id) ?: 0);
			if ($raw > 0.0) {
				return [$raw, $field_name];
			}
		}
		return [0.0, ''];
	}

	/**
	 * Resolve a unit price using a cascade-to-previous-round fallback strategy.
	 *
	 * @param int    $set_id     Set ID.
	 * @param int    $q_items    Number of distinct items in cart for this Set.
	 * @param int    $round      Current pricing round (1/2/3).
	 * @param string $group_tier 'ind', 'g5', or 'g10'.
	 * @return array{0: float, 1: bool, 2: string}
	 */
	public function resolve_price(
		int    $set_id,
		int    $q_items,
		int    $round,
		string $group_tier
	): array {
		$unit_price   = 0.0;
		$is_package   = false;
		$matched_rule = '';

		if ($q_items > 1) {
			$w_capped     = min(10, $q_items);
			$field_base   = "price_{$w_capped}el_round";
			[$raw, $rule] = $this->get_price_cascade($set_id, $field_base, $group_tier, $round);

			if ($raw > 0.0) {
				$unit_price   = $raw / $w_capped;
				$is_package   = true;
				$matched_rule = $rule;
			}
		}

		if ($unit_price <= 0.0) {
			[$unit_price, $matched_rule] = $this->get_price_cascade($set_id, 'price_1el_round', $group_tier, $round);
		}

		if ($unit_price <= 0.0) {
			$matched_rule = 'fallback_woocommerce_price';
		}

		return [$unit_price, $is_package, $matched_rule];
	}
}
