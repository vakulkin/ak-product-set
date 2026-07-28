<?php

/**
 * Plugin Name: AK Product Set
 * Plugin URI: https://akademiablanika.pl
 * Description: Specialized WooCommerce extension for multi-weekend training courses, dynamic 3D pricing, participant registration, and stock decomposition.
 * Version: 2.0.0
 * Text Domain: ak-product-set
 * WC requires at least: 7.0
 * WC tests up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AK_SET_VERSION', '2.0.0');
define('AK_SET_FILE', __FILE__);
define('AK_SET_PATH', plugin_dir_path(__FILE__));
define('AK_SET_URL', plugin_dir_url(__FILE__));

/**
 * PSR-4 Autoloader for AK_Set namespace
 */
spl_autoload_register(function ($class) {
    $prefix = 'AK_Set\\';
    $base_dir = AK_SET_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Declare High-Performance Order Storage (HPOS) compatibility
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', AK_SET_FILE, true);
    }
});

/**
 * Bootstrap Plugin Instance
 */
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>' . esc_html__('AK Product Set requires WooCommerce to be installed and active.', 'ak-product-set') . '</p></div>';
        });
        return;
    }

    \AK_Set\Plugin::instance()->boot();
});
