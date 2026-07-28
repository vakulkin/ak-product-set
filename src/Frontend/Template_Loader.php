<?php

namespace AK_Set\Frontend;

if (!defined('ABSPATH')) {
    exit;
}

class Template_Loader {
    /**
     * Locate template file with theme override support and path traversal protection
     *
     * @param string $template_name
     * @return string
     */
    public static function locate_template($template_name) {
        // Sanitize template path relative to templates directory to prevent directory traversal
        $clean_relative = ltrim(str_replace(['../', '..\\'], '', $template_name), '/\\');

        // Theme override path: wp-content/themes/your-theme/woocommerce/ak-product-set/...
        $theme_file = get_stylesheet_directory() . '/woocommerce/ak-product-set/' . $clean_relative;

        if (file_exists($theme_file)) {
            return $theme_file;
        }

        // Plugin default path: wp-content/plugins/ak-product-set/templates/...
        $plugin_file = AK_SET_PATH . 'templates/' . $clean_relative;

        if (file_exists($plugin_file)) {
            return $plugin_file;
        }

        return '';
    }

    /**
     * Render template file with isolated arguments
     *
     * @param string $template_name
     * @param array $args
     */
    public static function render($template_name, array $args = []) {
        $file = self::locate_template($template_name);

        if (!$file) {
            return;
        }

        extract($args);
        include $file;
    }

    /**
     * Return rendered template HTML as string
     *
     * @param string $template_name
     * @param array $args
     * @return string
     */
    public static function get_template_html($template_name, array $args = []) {
        ob_start();
        self::render($template_name, $args);
        return ob_get_clean();
    }
}
