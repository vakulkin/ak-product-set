<?php
/**
 * PHPUnit bootstrap file
 *
 * IMPORTANT load order:
 *  1. WP constants (ABSPATH etc.)
 *  2. Composer autoloader
 *  3. Patchwork — MUST come before any user-land WP function stubs
 *     so that Brain\Monkey can intercept/redefine those functions.
 *  4. Only "safe" stubs that we will never need to override in tests.
 */

// 1. WordPress constants
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', false);
}

// 2. Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// 3. Patchwork must come BEFORE any stub definitions
require_once dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';

// 4. Static helper stubs — functions that are used but never need to be
//    overridden via Brain\Monkey\Functions\when() in any test.
//    DO NOT add get_post, get_field, check_ajax_referer, or WC here —
//    leave those undefined so Brain\Monkey can intercept them at test-time.

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return $url;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key));
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($str) {
        return trim($str);
    }
}

if (!function_exists('is_email')) {
    function is_email($email) {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int) $maybeint);
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0) {
        return number_format($number, $decimals);
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default') {
        return (int)$number === 1 ? $single : $plural;
    }
}

if (!function_exists('sprintf')) {
    // already a builtin — no stub needed
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) {
        throw new \Exception(json_encode($data));
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) {
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return time();
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($id = 0) {
        return 'http://example.com/?p=' . $id;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $default;
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type($post = null) {
        return 'product';
    }
}

if (!function_exists('wc_get_checkout_url')) {
    function wc_get_checkout_url() {
        return 'http://example.com/checkout/';
    }
}

if (!function_exists('wc_get_cart_url')) {
    function wc_get_cart_url() {
        return 'http://example.com/cart/';
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return false;
    }
}

if (!function_exists('did_action')) {
    function did_action($tag) {
        return 0;
    }
}
