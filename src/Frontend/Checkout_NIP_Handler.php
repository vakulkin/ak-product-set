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
        // 1. Add NIP field to WooCommerce Checkout
        add_filter('woocommerce_billing_fields', [$this, 'add_billing_nip_field'], 20, 1);

        // 2. Enqueue frontend checkout scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_assets']);

        // 3. AJAX endpoints for frontend lookup
        add_action('wp_ajax_ak_lookup_nip',        [$this, 'ajax_lookup_nip']);
        add_action('wp_ajax_nopriv_ak_lookup_nip', [$this, 'ajax_lookup_nip']);

        // 4. Save NIP to WooCommerce Order Meta
        add_action('woocommerce_checkout_create_order', [$this, 'save_nip_to_order'], 10, 2);

        // 5. Display NIP in WooCommerce Admin Order Edit Screen
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'display_nip_in_admin_order'], 10, 1);
    }

    /**
     * Add NIP and single company data textarea to billing fields.
     *
     * @param array $fields
     * @return array
     */
    public function add_billing_nip_field(array $fields): array {
        $fields['billing_nip'] = [
            'type'        => 'text',
            'label'       => __('NIP (faktura VAT)', 'ak-product-set'),
            'placeholder' => __('Wpisz NIP (np. 1234567890)', 'ak-product-set'),
            'required'    => false,
            'class'       => ['form-row-wide', 'ak-nip-row'],
            'priority'    => 120, // last field in billing section
            'clear'       => true,
        ];

        $fields['billing_company_data'] = [
            'type'              => 'textarea',
            'label'             => __('Dane firmy do faktury', 'ak-product-set'),
            'placeholder'       => __('Nazwa firmy, adres, kod pocztowy, miejscowość', 'ak-product-set'),
            'required'          => false,
            'class'             => ['form-row-wide', 'ak-company-data-row'],
            'priority'          => 121,
            'clear'             => true,
            'custom_attributes' => [
                'rows' => '3',
            ],
        ];

        return $fields;
    }

    /**
     * Enqueue NIP lookup script and styles on checkout page.
     */
    public function enqueue_checkout_assets(): void {
        if (!function_exists('is_checkout') || !is_checkout()) {
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
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? __('Nie udało się pobrać danych firmy.', 'ak-product-set'));
        }
    }

    /**
     * Save NIP and company data to order metadata and billing address.
     *
     * @param \WC_Order $order
     * @param array $data
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
    }

    /**
     * Display NIP and company billing data in WooCommerce Admin Order Edit screen.
     *
     * @param \WC_Order $order
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
}
