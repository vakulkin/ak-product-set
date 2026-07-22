<?php
/**
 * Order processing: save participant meta and hide internal keys.
 *
 * @package AK_Set
 */

declare(strict_types=1);

namespace AK_Set;

/**
 * Class Order_Handler
 *
 * Copies _ak_* cart item meta to order line items at checkout, and hides
 * those raw keys from the WooCommerce admin order detail UI.
 */
class Order_Handler {

	/**
	 * Internal meta keys managed by this plugin.
	 *
	 * @var string[]
	 */
	private const AK_META_KEYS = [
		'_ak_participant_data',
		'_ak_slot_id',
		'_ak_set_id',
		'_ak_applied_price',
		'_ak_price_rule',
	];

	public function register_hooks(): void {
		add_action(
			'woocommerce_checkout_create_order_line_item',
			[ $this, 'save_line_item_meta' ],
			10,
			4
		);

		add_filter(
			'woocommerce_hidden_order_itemmeta',
			[ $this, 'hide_internal_meta' ]
		);

		// Optional: display participant data in admin order items table.
		add_action(
			'woocommerce_after_order_itemmeta',
			[ $this, 'admin_display_participant_data' ],
			10,
			3
		);
	}

	// -------------------------------------------------------------------------
	// Save meta to order line items
	// -------------------------------------------------------------------------

	/**
	 * Copy all _ak_* keys from the cart item array to the order line item.
	 * Because the cart is already split into quantity-1 items (one per participant),
	 * this is a simple copy operation with no looping required.
	 *
	 * @param \WC_Order_Item_Product $item             The order line item.
	 * @param string                 $_cart_item_key   Cart item key (required by hook, unused here).
	 * @param array                  $values           Cart item data array.
	 * @param \WC_Order              $_order           The order (required by hook, unused here).
	 */
	public function save_line_item_meta(
		\WC_Order_Item_Product $item,
		string                 $_cart_item_key,
		array                  $values,
		\WC_Order              $_order
	): void {
		foreach ( self::AK_META_KEYS as $key ) {
			if ( isset( $values[ $key ] ) ) {
				$item->add_meta_data( $key, $values[ $key ], true );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Hide raw keys from admin UI
	// -------------------------------------------------------------------------

	/**
	 * Add internal _ak_* keys to the list of hidden order item meta.
	 * This suppresses the raw arrays from showing in the standard meta table.
	 *
	 * @param string[] $hidden_keys Currently hidden meta keys.
	 * @return string[] Extended array.
	 */
	public function hide_internal_meta( array $hidden_keys ): array {
		return array_merge( $hidden_keys, self::AK_META_KEYS );
	}

	// -------------------------------------------------------------------------
	// Optional: admin display
	// -------------------------------------------------------------------------

	/**
	 * Render a clean participant card below the order line item meta in the
	 * WooCommerce admin order detail page.
	 *
	 * @param int                    $_item_id  Order item ID (required by hook, unused here).
	 * @param \WC_Order_Item_Product $item      Order item.
	 * @param \WC_Product|false      $_product  Product object (required by hook, unused here).
	 */
	public function admin_display_participant_data(
		int                    $_item_id,
		\WC_Order_Item_Product $item,
					           $_product
	): void {
		$data = $item->get_meta( '_ak_participant_data' );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return;
		}

		$name  = $data['name']  ?? '';
		$size  = $data['size']  ?? '';
		$cut   = $data['cut']   ?? '';
		$price = $item->get_meta( '_ak_applied_price' );
		$slot  = $item->get_meta( '_ak_slot_id' );

		echo '<div class="ak-admin-participant" style="background:#f6f7f7;border-left:3px solid #0073aa;padding:6px 10px;margin:4px 0;font-size:12px;">';

		if ( $name ) {
			echo '<strong>' . esc_html__( 'Uczestnik:', 'ak-product-set' ) . '</strong> ' . esc_html( $name ) . '<br>';
		}
		if ( $size ) {
			echo '<strong>' . esc_html__( 'Rozmiar:', 'ak-product-set' ) . '</strong> ' . esc_html( $size ) . '<br>';
		}
		if ( $cut ) {
			echo '<strong>' . esc_html__( 'Krój:', 'ak-product-set' ) . '</strong> ' . esc_html( $cut ) . '<br>';
		}
		if ( $price !== '' && $price !== null ) {
			echo '<strong>' . esc_html__( 'Cena:', 'ak-product-set' ) . '</strong> ' . wc_price( (float) $price ) . '<br>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $slot ) {
			echo '<small style="color:#999;">ID: ' . esc_html( $slot ) . '</small>';
		}

		echo '</div>';
	}
}
