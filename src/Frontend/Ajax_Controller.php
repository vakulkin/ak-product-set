<?php

namespace AK_Set\Frontend;

use AK_Set\Cart\Composed_Cart_Manager;
use AK_Set\Pricing\Pricing_Engine;
use AK_Set\Models\Participant_Model;

if (!defined('ABSPATH')) {
    exit;
}

class Ajax_Controller {

    public function init() {
        // Calculation endpoint
        add_action('wp_ajax_ak_calculate_set_price', [$this, 'handle_calculate_price']);
        add_action('wp_ajax_nopriv_ak_calculate_set_price', [$this, 'handle_calculate_price']);

        // Add to cart endpoint
        add_action('wp_ajax_ak_add_set_to_cart', [$this, 'handle_add_to_cart']);
        add_action('wp_ajax_nopriv_ak_add_set_to_cart', [$this, 'handle_add_to_cart']);

        // Clear cart endpoint
        add_action('wp_ajax_ak_clear_set_cart', [$this, 'handle_clear_cart']);
        add_action('wp_ajax_nopriv_ak_clear_set_cart', [$this, 'handle_clear_cart']);
    }

    /**
     * AJAX Endpoint: Server-Side Dynamic Price & Stock Calculation
     */
    public function handle_calculate_price() {
        check_ajax_referer('ak_set_nonce', 'nonce');

        $set_id = isset($_POST['set_id']) ? absint($_POST['set_id']) : 0;
        $selected_weekends = isset($_POST['selected_weekends']) && is_array($_POST['selected_weekends']) ? array_map('absint', $_POST['selected_weekends']) : [];
        $headcount = isset($_POST['headcount']) ? absint($_POST['headcount']) : 1;

        if (!$set_id || empty($selected_weekends)) {
            wp_send_json_error([
                'message' => __('Wybierz co najmniej jeden termin.', 'ak-product-set'),
            ]);
        }

        $calc = Pricing_Engine::calculate($set_id, $selected_weekends, $headcount);

        if (!$calc['valid']) {
            wp_send_json_error([
                'message' => isset($calc['error']) ? esc_html($calc['error']) : __('Nieprawidłowa kalkulacja ceny.', 'ak-product-set'),
            ]);
        }

        $tier_labels = [
            'ind' => __('1 osoba (Indywidualna)', 'ak-product-set'),
            'g5'  => __('Grupa 5-9 osób (Rabat Grupowy)', 'ak-product-set'),
            'g10' => __('Grupa 10+ osób (Max Rabat)', 'ak-product-set'),
        ];

        $calc['formatted'] = [
            'per_person_price'  => function_exists('wc_price') ? wc_price($calc['per_person_price']) : number_format_i18n($calc['per_person_price'], 2) . ' zł',
            'total_price'       => function_exists('wc_price') ? wc_price($calc['total_price']) : number_format_i18n($calc['total_price'], 2) . ' zł',
            'per_person_raw'    => number_format_i18n($calc['per_person_price'], 2) . ' zł',
            'total_raw'         => number_format_i18n($calc['total_price'], 2) . ' zł',
            'package_size_text' => sprintf(_n('%d weekend', '%d weekendy', $calc['package_size'], 'ak-product-set'), $calc['package_size']),
            'round_text'        => sprintf(__('Runda %d', 'ak-product-set'), $calc['round']),
            'tier_text'         => isset($tier_labels[$calc['tier']]) ? $tier_labels[$calc['tier']] : $calc['tier'],
            'stock_note_text'   => '',
        ];

        wp_send_json_success($calc);
    }

    /**
     * AJAX Endpoint: Add/Replace Composed Set in Cart (with Server Stock Check)
     */
    public function handle_add_to_cart() {
        check_ajax_referer('ak_set_nonce', 'nonce');

        $set_id = isset($_POST['set_id']) ? absint($_POST['set_id']) : 0;
        $selected_weekends = isset($_POST['selected_weekends']) && is_array($_POST['selected_weekends']) ? array_map('absint', $_POST['selected_weekends']) : [];
        $headcount = isset($_POST['headcount']) ? absint($_POST['headcount']) : 1;
        $raw_participants = isset($_POST['participants']) && is_array($_POST['participants']) ? $_POST['participants'] : [];
        $raw_participants = array_slice($raw_participants, 0, $headcount);

        if (!$set_id || empty($selected_weekends)) {
            wp_send_json_error([
                'message' => __('Musisz wybrać co najmniej jeden termin (weekend).', 'ak-product-set'),
            ]);
        }

        $set = new \AK_Set\Models\Set_Model($set_id);
        if (!$set->exists()) {
            wp_send_json_error([
                'message' => __('Zestaw nie istnieje.', 'ak-product-set'),
            ]);
        }

        $has_tshirt = $set->has_tshirt();

        // Server-side validation of participant fields
        foreach ($raw_participants as $idx => $p) {
            $p_num = $idx + 1;
            $name  = isset($p['name']) ? trim($p['name']) : '';
            $email = isset($p['email']) ? trim($p['email']) : '';
            $phone = isset($p['phone']) ? trim($p['phone']) : '';
            $size  = isset($p['tshirt_size']) ? trim($p['tshirt_size']) : '';

            if (empty($name)) {
                wp_send_json_error([
                    'message' => sprintf(__('Proszę podać imię i nazwisko dla Uczestnika %d.', 'ak-product-set'), $p_num),
                ]);
            }

            if (empty($email) || !\is_email($email)) {
                wp_send_json_error([
                    'message' => sprintf(__('Proszę podać prawidłowy adres e-mail dla Uczestnika %d (np. jan@example.com).', 'ak-product-set'), $p_num),
                ]);
            }

            $clean_phone = preg_replace('/[^0-9]/', '', $phone);
            if (empty($phone) || strlen($clean_phone) < 7 || strlen($clean_phone) > 15) {
                wp_send_json_error([
                    'message' => sprintf(__('Proszę podać prawidłowy numer telefonu dla Uczestnika %d (np. +48 600 000 000).', 'ak-product-set'), $p_num),
                ]);
            }

            if ($has_tshirt && empty($size)) {
                wp_send_json_error([
                    'message' => sprintf(__('Proszę wybrać rozmiar koszulki dla Uczestnika %d.', 'ak-product-set'), $p_num),
                ]);
            }
        }

        // Sanitize participant models
        $participant_models = Participant_Model::from_collection($raw_participants);
        $participants = array_map(function ($p) {
            return $p->to_array();
        }, $participant_models);

        try {
            $cart_manager = new Composed_Cart_Manager();
            $cart_item_key = $cart_manager->add_set_to_cart($set_id, $selected_weekends, $headcount, $participants);

            if (!$cart_item_key) {
                wp_send_json_error([
                    'message' => __('Nie udało się dodać zestawu do koszyka.', 'ak-product-set'),
                ]);
            }

            wp_send_json_success([
                'message'      => __('Zestaw został pomyślnie dodany do koszyka.', 'ak-product-set'),
                'redirect_url' => wc_get_checkout_url(),
                'cart_url'     => wc_get_cart_url(),
            ]);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => esc_html($e->getMessage()),
            ]);
        }
    }

    /**
     * AJAX Endpoint: Clear Set from Cart
     */
    public function handle_clear_cart() {
        check_ajax_referer('ak_set_nonce', 'nonce');

        $cart_manager = new Composed_Cart_Manager();
        $cart_manager->clear_existing_set_cart_items();

        wp_send_json_success([
            'message' => __('Zestaw został usunięty z koszyka.', 'ak-product-set'),
        ]);
    }
}
