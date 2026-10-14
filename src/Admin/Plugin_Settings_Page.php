<?php

namespace AK_Set\Admin;

use AK_Set\Order\Order_Recalculator;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin_Settings_Page {

    const MENU_SLUG    = 'ak-set-settings';
    const OPTION_GROUP = 'ak_set_settings_group';

    public function init(): void {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Register settings submenu under AK Zestawy (edit.php?post_type=ak_set)
     */
    public function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=ak_set',
            __('Ustawienia AK Zestawy', 'ak-product-set'),
            __('Ustawienia', 'ak-product-set'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            'ak_set_tshirt_cost',
            [
                'type'              => 'number',
                'sanitize_callback' => [$this, 'sanitize_cost'],
                'default'           => Order_Recalculator::DEFAULT_TSHIRT_COST,
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            'ak_ceidg_api_token',
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ]
        );
    }

    /**
     * Sanitize cost value
     *
     * @param mixed $value
     */
    public function sanitize_cost($value): float {
        $num = (float) str_replace(',', '.', (string) $value);
        return max(0.0, round($num, 2));
    }

    /**
     * Render settings page
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Brak uprawnień.', 'ak-product-set'));
        }

        $current_cost  = Order_Recalculator::get_tshirt_cost();
        $current_token = get_option('ak_ceidg_api_token', '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ustawienia AK Zestawy', 'ak-product-set'); ?></h1>
            <p class="description">
                <?php esc_html_e('Główne parametry kalkulacji i integracji modułu zestawów i obozów gimnastycznych.', 'ak-product-set'); ?>
            </p>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="ak_set_tshirt_cost">
                                    <?php esc_html_e('Koszt wyprodukowanej koszulki (zł)', 'ak-product-set'); ?>
                                </label>
                            </th>
                            <td>
                                <input 
                                    name="ak_set_tshirt_cost" 
                                    type="number" 
                                    step="0.01" 
                                    min="0" 
                                    id="ak_set_tshirt_cost" 
                                    value="<?php echo esc_attr(number_format($current_cost, 2, '.', '')); ?>" 
                                    class="regular-text" 
                                />
                                <p class="description">
                                    <?php esc_html_e('Domyślna kwota w PLN potrącana przy rezygnacji/anulowaniu uczestnictwa, jeżeli koszulka została już wyprodukowana lub wydana (domyślnie: 50.00 zł).', 'ak-product-set'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="ak_ceidg_api_token">
                                    <?php esc_html_e('Token API CEIDG v2 (opcjonalny)', 'ak-product-set'); ?>
                                </label>
                            </th>
                            <td>
                                <input 
                                    name="ak_ceidg_api_token" 
                                    type="password" 
                                    id="ak_ceidg_api_token" 
                                    value="<?php echo esc_attr($current_token); ?>" 
                                    class="regular-text" 
                                    autocomplete="off"
                                />
                                <p class="description">
                                    <?php esc_html_e('Klucz API z serwisu dane.biznes.gov.pl. Używany jako rezerwowe źródło (fallback) dla Białej Listy VAT do pobierania danych jednoosobowych działalności gospodarczych po NIP.', 'ak-product-set'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Zapisz ustawienia', 'ak-product-set')); ?>
            </form>
        </div>
        <?php
    }
}
