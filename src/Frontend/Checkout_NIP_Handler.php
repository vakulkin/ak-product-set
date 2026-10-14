<?php

namespace AK_Set\Frontend;

use AK_Set\Services\Company_Lookup_Service;
use AK_Set\Support\NIP_Validator;

if (!defined('ABSPATH')) {
    exit;
}

class Checkout_NIP_Handler {

    const NONCE_ACTION = 'ak_nip_lookup_nonce';

    public function init(): void {
        // 1. Add NIP field to WooCommerce Checkout & Account Address with highest priority (9999)
        // so field-remover plugins running at default (10, 20) or high (100, 999) don't strip them.
        add_filter('woocommerce_billing_fields',  [$this, 'add_billing_nip_field'], 9999, 1);
        add_filter('woocommerce_checkout_fields', [$this, 'ensure_checkout_nip_fields'], 9999, 1);

        // 2. Persistent field value retrieval for checkout form
        add_filter('woocommerce_checkout_get_value', [$this, 'get_checkout_field_value'], 10, 2);

        // 3. Persist fields into WooCommerce session on AJAX checkout review update
        add_action('woocommerce_checkout_update_order_review', [$this, 'persist_checkout_fields_to_session'], 10, 1);

        // 4. Save NIP and company data to user account on order completion / account creation
        add_action('woocommerce_checkout_update_user_meta', [$this, 'update_user_meta_on_checkout'], 10, 2);

        // 5. Enqueue frontend checkout & account scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_assets']);

        // 6. AJAX endpoints for frontend lookup
        add_action('wp_ajax_ak_lookup_nip',        [$this, 'ajax_lookup_nip']);
        add_action('wp_ajax_nopriv_ak_lookup_nip', [$this, 'ajax_lookup_nip']);

        // 7. Save NIP to WooCommerce Order Meta and User Meta
        add_action('woocommerce_checkout_create_order', [$this, 'save_nip_to_order'], 10, 2);

        // 8. Display NIP in WooCommerce Admin Order Edit Screen
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'display_nip_in_admin_order'], 10, 1);

        // 9. Display & manage NIP in WordPress Admin User Profile (wp-admin/user-edit.php)
        add_filter('woocommerce_customer_meta_fields', [$this, 'add_customer_meta_fields']);
    }

    /**
     * Resolve saved value from WC session or logged in user meta.
     */
    public function resolve_saved_value(string $key): string {
        // 1. Session first
        if (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
            $sess_val = WC()->session->get($key);
            if (!empty($sess_val)) {
                return (string) $sess_val;
            }
        }

        // 2. User meta
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id > 0 && function_exists('get_user_meta')) {
            $meta_val = get_user_meta($user_id, $key, true);
            if (!empty($meta_val)) {
                return (string) $meta_val;
            }
        }

        return '';
    }

    /**
     * Ensure field values on checkout form are populated from session / user meta.
     *
     * @param mixed $value
     * @return mixed
     */
    public function get_checkout_field_value($value, string $input) {
        if ($value !== null && $value !== '') {
            return $value;
        }

        if ($input === 'billing_nip' || $input === 'billing_company_data') {
            $saved = $this->resolve_saved_value($input);
            if ($saved !== '') {
                return $saved;
            }
        }

        return $value;
    }

    /**
     * Add NIP and single company data textarea to billing fields.
     */
    public function add_billing_nip_field(array $fields): array {
        $default_nip          = $this->resolve_saved_value('billing_nip');
        $default_company_data = $this->resolve_saved_value('billing_company_data');

        $fields['billing_nip'] = [
            'type'        => 'text',
            'label'       => __('NIP (faktura VAT)', 'ak-product-set'),
            'placeholder' => __('Wpisz NIP (np. 1234567890)', 'ak-product-set'),
            'required'    => false,
            'class'       => ['form-row-wide', 'ak-nip-row'],
            'priority'    => 120, // last field in billing section
            'clear'       => true,
            'default'     => $default_nip,
        ];

        $fields['billing_company_data'] = [
            'type'              => 'textarea',
            'label'             => __('Dane firmy do faktury', 'ak-product-set'),
            'placeholder'       => __('Nazwa firmy, adres, kod pocztowy, miejscowość', 'ak-product-set'),
            'required'          => false,
            'class'             => ['form-row-wide', 'ak-company-data-row'],
            'priority'          => 121,
            'clear'             => true,
            'default'           => $default_company_data,
            'custom_attributes' => [
                'rows' => '3',
            ],
        ];

        return $fields;
    }

    /**
     * Ensure fields are preserved in woocommerce_checkout_fields (in case other plugins override 'billing').
     */
    public function ensure_checkout_nip_fields(array $fields): array {
        if (!isset($fields['billing']) || !is_array($fields['billing'])) {
            $fields['billing'] = [];
        }

        $fields['billing'] = $this->add_billing_nip_field($fields['billing']);

        return $fields;
    }

    /**
     * Persist fields into WooCommerce session during AJAX checkout review updates.
     *
     * @param string $post_data
     */
    public function persist_checkout_fields_to_session($post_data): void {
        if (empty($post_data) || !is_string($post_data)) {
            return;
        }

        parse_str($post_data, $data);
        if (!function_exists('WC') || !WC() || !isset(WC()->session) || !WC()->session) {
            return;
        }

        if (isset($data['billing_nip'])) {
            WC()->session->set('billing_nip', sanitize_text_field($data['billing_nip']));
        }
        if (isset($data['billing_company_data'])) {
            WC()->session->set('billing_company_data', sanitize_textarea_field($data['billing_company_data']));
        }
    }

    /**
     * Update user account meta when checkout finishes or new account is created.
     */
    public function update_user_meta_on_checkout(int $customer_id, array $posted): void {
        if ($customer_id <= 0 || !function_exists('update_user_meta')) {
            return;
        }

        if (!empty($posted['billing_nip'])) {
            update_user_meta($customer_id, 'billing_nip', NIP_Validator::clean($posted['billing_nip']));
        }
        if (isset($posted['billing_company_data'])) {
            update_user_meta($customer_id, 'billing_company_data', sanitize_textarea_field($posted['billing_company_data']));
        }
    }

    /**
     * Enqueue NIP lookup script and styles on checkout & account pages.
     */
    public function enqueue_checkout_assets(): void {
        $is_checkout = function_exists('is_checkout') && is_checkout();
        $is_account  = function_exists('is_account_page') && is_account_page();

        if (!$is_checkout && !$is_account) {
            return;
        }

        $plugin_url = plugins_url('', dirname(__DIR__, 1));

        wp_enqueue_style(
            'ak-checkout-nip-css',
            $plugin_url . '/assets/css/ak-checkout-nip.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'ak-checkout-nip-js',
            $plugin_url . '/assets/js/ak-checkout-nip.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('ak-checkout-nip-js', 'akNipConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'i18n'    => [
                'fetchBtn'     => __('Pobierz dane firmy', 'ak-product-set'),
                'refreshBtn'   => __('Odśwież dane firmy', 'ak-product-set'),
                'loading'      => __('Pobieranie danych...', 'ak-product-set'),
                'successBL'    => __('✓ Pobrano dane z Białej Listy VAT', 'ak-product-set'),
                'successCEIDG' => __('✓ Pobrano dane z rejestru CEIDG', 'ak-product-set'),
                'invalidNip'   => __('Nieprawidłowy numer NIP (błędna suma kontrolna)', 'ak-product-set'),
                'notFound'     => __('Nie znaleziono firmy — możesz wpisać dane ręcznie', 'ak-product-set'),
            ],
        ]);
    }

    /**
     * AJAX handler to lookup company data by NIP.
     */
    public function ajax_lookup_nip(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $nip = isset($_POST['nip']) ? sanitize_text_field($_POST['nip']) : '';
        $result = Company_Lookup_Service::lookup($nip);

        if ($result['success']) {
            if (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
                WC()->session->set('billing_nip', $nip);
                if (!empty($result['data']['formatted'])) {
                    WC()->session->set('billing_company_data', $result['data']['formatted']);
                }
            }

            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? __('Nie udało się pobrać danych firmy.', 'ak-product-set'));
        }
    }

    /**
     * Save NIP and company data to order metadata and billing address, and persist to user profile & session.
     */
    public function save_nip_to_order(\WC_Order $order, array $data): void {
        $nip = '';
        if (isset($_POST['billing_nip'])) {
            $nip = sanitize_text_field($_POST['billing_nip']);
        } elseif (isset($data['billing_nip'])) {
            $nip = sanitize_text_field($data['billing_nip']);
        }

        $company_data = '';
        if (isset($_POST['billing_company_data'])) {
            $company_data = sanitize_textarea_field($_POST['billing_company_data']);
        } elseif (isset($data['billing_company_data'])) {
            $company_data = sanitize_textarea_field($data['billing_company_data']);
        }

        if ($nip !== '') {
            $clean_nip = NIP_Validator::clean($nip);
            $order->update_meta_data('_billing_nip', $clean_nip);
            $order->update_meta_data('billing_nip', $clean_nip);
            $order->update_meta_data('_billing_vat_id', $clean_nip);
            $order->update_meta_data('_vat_number', $clean_nip);
        }

        if ($company_data !== '') {
            $order->update_meta_data('_billing_company_data', $company_data);
            $order->update_meta_data('billing_company_data', $company_data);

            // Populate WooCommerce billing company & address so invoice plugins pick them up
            $current_company = (string) $order->get_billing_company();
            $current_address = (string) $order->get_billing_address_1();

            $lines = array_values(array_filter(array_map('trim', explode("\n", $company_data))));
            if (count($lines) <= 1) {
                $lines = array_values(array_filter(array_map('trim', explode(',', $company_data))));
            }

            if (!empty($lines)) {
                $first_line = array_shift($lines);
                if (empty($current_company)) {
                    $order->set_billing_company($first_line);
                }
            } elseif (empty($current_company)) {
                $order->set_billing_company($company_data);
            }

            if (empty($current_address)) {
                $order->set_billing_address_1($company_data);
            } else {
                $order->set_billing_address_1(rtrim($current_address, ', ') . ', ' . $company_data);
            }
        }

        // Also persist in WC session
        if (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
            if ($nip !== '') {
                WC()->session->set('billing_nip', $nip);
            }
            if ($company_data !== '') {
                WC()->session->set('billing_company_data', $company_data);
            }
        }

        // Also persist in customer user meta (account)
        $customer_id = (int) $order->get_customer_id();
        if ($customer_id <= 0 && function_exists('get_current_user_id')) {
            $customer_id = (int) get_current_user_id();
        }

        if ($customer_id > 0 && function_exists('update_user_meta')) {
            if ($nip !== '') {
                update_user_meta($customer_id, 'billing_nip', NIP_Validator::clean($nip));
            }
            if ($company_data !== '') {
                update_user_meta($customer_id, 'billing_company_data', $company_data);
            }
        }
    }

    /**
     * Display NIP and company billing data in WooCommerce Admin Order Edit screen.
     */
    public function display_nip_in_admin_order(\WC_Order $order): void {
        $nip = $order->get_meta('_billing_nip');
        if (!$nip) {
            $nip = $order->get_meta('billing_nip');
        }
        if (!$nip) {
            $nip = $order->get_meta('_billing_vat_id');
        }

        $company_data = $order->get_meta('_billing_company_data');
        if (!$company_data) {
            $company_data = $order->get_meta('billing_company_data');
        }

        if ($nip) {
            echo '<p style="margin-top: 8px;"><strong>' . esc_html__('NIP (Faktura):', 'ak-product-set') . '</strong> ' . esc_html($nip) . '</p>';
        }

        if ($company_data) {
            echo '<p><strong>' . esc_html__('Dane firmy do faktury:', 'ak-product-set') . '</strong><br>' . nl2br(esc_html($company_data)) . '</p>';
        }
    }

    /**
     * Add NIP and company data to WordPress Admin User Profile edit screen.
     */
    public function add_customer_meta_fields(array $fields): array {
        if (!isset($fields['billing']['fields']) || !is_array($fields['billing']['fields'])) {
            $fields['billing']['fields'] = [];
        }

        $fields['billing']['fields']['billing_nip'] = [
            'label'       => __('NIP (faktura VAT)', 'ak-product-set'),
            'description' => __('Numer NIP do faktury', 'ak-product-set'),
        ];

        $fields['billing']['fields']['billing_company_data'] = [
            'label'       => __('Dane firmy do faktury', 'ak-product-set'),
            'description' => __('Nazwa firmy, adres, kod pocztowy, miejscowość', 'ak-product-set'),
            'type'        => 'textarea',
        ];

        return $fields;
    }
}
