<?php

/**
 * Plugin Name: AK Product Set
 * Description: Groups WooCommerce products into training "Sets" with participant management and dynamic tiered pricing.
 * Version:     1.0.0
 * Text Domain: ak-product-set
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires at least: 6.2
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

declare(strict_types=1);

namespace AK_Set;

if (! defined('ABSPATH')) {
	exit;
}

// Plugin constants.
define('AK_SET_VERSION',     '1.0.0');
define('AK_SET_DIR',         plugin_dir_path(__FILE__));
define('AK_SET_URL',         plugin_dir_url(__FILE__));
define('AK_SET_PLUGIN_FILE', __FILE__);

// Require all includes.
$ak_set_includes = [
	'includes/class-set-validator.php',
	'includes/class-cpt-set.php',
	'includes/class-participant-form.php',
	'includes/class-cart-handler.php',
	'includes/class-order-handler.php',
	'includes/class-plugin.php',
];

foreach ($ak_set_includes as $_file) {
	require_once AK_SET_DIR . $_file;
}
unset($_file);

/**
 * Boot the plugin after all plugins are loaded.
 * Checks for required dependencies before booting.
 */
add_action('plugins_loaded', static function (): void {
	if (! class_exists('\WooCommerce') || ! function_exists('acf_add_local_field_group')) {
		add_action('admin_notices', static function (): void {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: plugin name */
				esc_html__('%s requires WooCommerce and Advanced Custom Fields (Free) to be active.', 'ak-product-set'),
				'<strong>AK Product Set</strong>'
			);
			echo '</p></div>';
		});
		return;
	}

	Plugin::get_instance()->boot();
});

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action('before_woocommerce_init', static function (): void {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', AK_SET_PLUGIN_FILE, true);
	}
});
