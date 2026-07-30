<?php

namespace AK_Set\Frontend;

use AK_Set\Models\Set_Model;
use AK_Set\Support\Helper;

if (!defined('ABSPATH')) {
    exit;
}

class Shortcode_Handler
{

    public function init()
    {
        // Shortcode backup
        add_shortcode('ak_set', [$this, 'render_shortcode']);

        // Single ak_set CPT content filter (automatic wizard rendering on set page)
        add_filter('the_content', [$this, 'render_single_set_content']);

        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    /**
     * Register plugin frontend CSS & JS assets
     */
    public function register_assets()
    {
        $css_file = AK_SET_PATH . 'assets/css/ak-set-form.css';
        $js_file  = AK_SET_PATH . 'assets/js/ak-set-form.js';

        $css_ver = file_exists($css_file) ? AK_SET_VERSION . '.' . filemtime($css_file) : AK_SET_VERSION;
        $js_ver  = file_exists($js_file) ? AK_SET_VERSION . '.' . filemtime($js_file) : AK_SET_VERSION;

        wp_enqueue_style(
            'ak-set-form-css',
            AK_SET_URL . 'assets/css/ak-set-form.css',
            [],
            $css_ver
        );

        wp_register_script(
            'ak-set-form-js',
            AK_SET_URL . 'assets/js/ak-set-form.js',
            ['jquery'],
            $js_ver,
            true
        );
    }

    /**
     * Automatically append wizard on single ak_set post pages
     *
     * @param string $content
     * @return string
     */
    public function render_single_set_content($content)
    {
        if (is_singular('ak_set') && in_the_loop() && is_main_query()) {
            $set_id = get_the_ID();
            $wizard_html = $this->render_set_wizard($set_id);
            return $content . $wizard_html;
        }

        return $content;
    }

    /**
     * Look up existing composed set item from active WooCommerce cart to sync form data
     * Matches specific $set_id and uses the last added item for that set ID
     *
     * @param int $set_id
     * @return array|null
     */
    private function get_cart_initial_data($set_id)
    {
        if (!function_exists('WC') || !WC() || !WC()->cart) {
            return null;
        }

        $set_id = (int)$set_id;
        $matched_data = null;

        $cart = WC()->cart->get_cart();
        foreach ($cart as $cart_item) {
            if (!empty($cart_item['_ak_is_composed_set'])) {
                $item_set_id = isset($cart_item['_ak_set_id']) ? (int)$cart_item['_ak_set_id'] : 0;
                if ($item_set_id === $set_id) {
                    $matched_data = [
                        'selected_weekends' => isset($cart_item['_ak_selected_weekends']) ? array_map('intval', $cart_item['_ak_selected_weekends']) : [],
                        'headcount'         => isset($cart_item['_ak_headcount']) ? (int)$cart_item['_ak_headcount'] : 1,
                        'participants'      => isset($cart_item['_ak_participants']) ? $cart_item['_ak_participants'] : [],
                    ];
                }
            }
        }

        return $matched_data;
    }

    /**
     * Render [ak_set id="..."] shortcode
     *
     * @param array $atts
     * @return string
     */
    public function render_shortcode($atts)
    {
        $atts = shortcode_atts([
            'id' => get_the_ID(),
        ], $atts, 'ak_set');

        return $this->render_set_wizard((int)$atts['id']);
    }

    /**
     * Core Wizard Renderer for single set page or shortcode
     *
     * @param int $set_id
     * @return string
     */
    public function render_set_wizard($set_id)
    {
        $set = new Set_Model($set_id);

        if (!$set->exists()) {
            return '<div class="ak-set-error">' . esc_html__('Brak zestawu o podanym identyfikatorze.', 'ak-product-set') . '</div>';
        }

        $weekends = $set->get_weekend_products();
        if (empty($weekends)) {
            return '<div class="ak-set-error">' . esc_html__('Ten zestaw nie posiada jeszcze przypisanych terminów/weekendów.', 'ak-product-set') . '</div>';
        }

        // Check if there is an existing booking in cart to pre-load for editing
        $initial_data = $this->get_cart_initial_data($set_id);

        // Build set-level pricing matrix lookup table for JS client-side real-time calculation
        $set_matrix = [];
        $tiers = ['ind', 'g5', 'g10'];
        for ($x = 1; $x <= 10; $x++) {
            for ($y = 1; $y <= 3; $y++) {
                foreach ($tiers as $t) {
                    $key = Helper::get_pricing_field_key($x, $y, $t);
                    $set_matrix[$key] = $set->get_price_by_matrix($x, $y, $t);
                }
            }
        }

        // Prepare JSON payload for frontend script
        $weekends_data = [];
        foreach ($weekends as $w) {
            $weekends_data[] = [
                'id'                => $w->get_id(),
                'title'             => $w->get_title(),
                'managing_stock'    => $w->managing_stock(),
                'stock'             => $w->get_stock_quantity(),
                'is_expired'        => $w->is_expired(),
                'event_start'       => $w->get_event_start_datetime(),
                'event_end'         => $w->get_event_end_datetime(),
                'recruitment_start' => $w->get_recruitment_start_datetime(),
                'recruitment_end'   => $w->get_recruitment_end_datetime(),
                'location'          => $w->get_event_location(),
            ];
        }

        $js_config = [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('ak_set_nonce'),
            'set_id'       => $set_id,
            'has_tshirt'   => $set->has_tshirt(),
            'round_1_end'  => $set->get_round_1_end_date(),
            'round_2_end'  => $set->get_round_2_end_date(),
            'matrix'       => $set_matrix,
            'tshirt_sizes' => Helper::get_tshirt_sizes(),
            'tshirt_cuts'  => Helper::get_tshirt_cuts(),
            'weekends'     => $weekends_data,
            'initial_data' => $initial_data,
            'cart_url'     => wc_get_cart_url(),
            'checkout_url' => wc_get_checkout_url(),
        ];

        wp_enqueue_style('ak-set-form-css');
        wp_enqueue_script('ak-set-form-js');
        wp_localize_script('ak-set-form-js', 'akSetData', $js_config);

        return Template_Loader::get_template_html('frontend/wizard.php', [
            'set'          => $set,
            'weekends'     => $weekends,
            'js_config'    => $js_config,
            'initial_data' => $initial_data,
        ]);
    }
}
