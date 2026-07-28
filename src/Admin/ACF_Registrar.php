<?php

namespace AK_Set\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class ACF_Registrar {
    public function init() {
        add_action('init', [$this, 'register_cpt']);
        add_action('acf/init', [$this, 'register_acf_field_groups']);
    }

    /**
     * Register Custom Post Type ak_set ("AK Zestawy")
     */
    public function register_cpt() {
        $labels = [
            'name'               => _x('AK Zestawy', 'Post Type General Name', 'ak-product-set'),
            'singular_name'      => _x('AK Zestaw', 'Post Type Singular Name', 'ak-product-set'),
            'menu_name'          => __('AK Zestawy', 'ak-product-set'),
            'name_admin_bar'     => __('AK Zestaw', 'ak-product-set'),
            'all_items'          => __('Wszystkie Zestawy', 'ak-product-set'),
            'add_new_item'       => __('Dodaj Nowy Zestaw', 'ak-product-set'),
            'add_new'            => __('Dodaj Nowy', 'ak-product-set'),
            'new_item'           => __('Nowy Zestaw', 'ak-product-set'),
            'edit_item'          => __('Edytuj Zestaw', 'ak-product-set'),
            'update_item'        => __('Aktualizuj Zestaw', 'ak-product-set'),
            'view_item'          => __('Zobacz Zestaw', 'ak-product-set'),
            'search_items'       => __('Szukaj Zestawu', 'ak-product-set'),
        ];

        $args = [
            'label'               => __('AK Zestaw', 'ak-product-set'),
            'labels'              => $labels,
            'supports'            => ['title', 'editor', 'revisions'],
            'hierarchical'        => false,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_position'       => 26,
            'menu_icon'           => 'dashicons-grid-view',
            'show_in_admin_bar'   => true,
            'show_in_nav_menus'   => true,
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => true,
            'capability_type'     => 'post',
            'show_in_rest'        => true,
        ];

        register_post_type('ak_set', $args);
    }

    /**
     * Programmatically register ACF Field Groups
     */
    public function register_acf_field_groups() {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        // Group 1: AK Set Options
        acf_add_local_field_group([
            'key' => 'group_ak_set_options',
            'title' => __('Opcje Zestawu AK', 'ak-product-set'),
            'fields' => [
                [
                    'key' => 'field_ak_set_products',
                    'label' => __('Wybrane Weekendy (Produkty)', 'ak-product-set'),
                    'name' => 'set_products',
                    'type' => 'relationship',
                    'instructions' => __('Wybierz produkty WooCommerce poszczególnych weekendów należących do tego zestawu.', 'ak-product-set'),
                    'post_type' => ['product'],
                    'filters' => ['search'],
                    'return_format' => 'id',
                ],
                [
                    'key' => 'field_ak_set_has_tshirt',
                    'label' => __('Czy wymagana Koszulka?', 'ak-product-set'),
                    'name' => 'set_has_tshirt',
                    'type' => 'true_false',
                    'instructions' => __('Włącz, jeśli podczas rejestracji ma być wymagana deklaracja rozmiaru i kroju koszulki.', 'ak-product-set'),
                    'default_value' => 0,
                    'ui' => 1,
                ],
            ],
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'ak_set',
                    ],
                ],
            ],
        ]);

        // Group 2: Product Event & Recruitment Settings (on product)
        acf_add_local_field_group([
            'key' => 'group_ak_product_event_settings',
            'title' => __('Ustawienia Wydarzenia & Rekrutacji', 'ak-product-set'),
            'fields' => [
                [
                    'key' => 'field_ak_event_start_datetime',
                    'label' => __('Data i godzina rozpoczęcia wydarzenia', 'ak-product-set'),
                    'name' => 'ak_event_start_datetime',
                    'type' => 'date_time_picker',
                    'display_format' => 'Y-m-d H:i',
                    'return_format' => 'Y-m-d H:i:s',
                ],
                [
                    'key' => 'field_ak_event_end_datetime',
                    'label' => __('Data i godzina zakończenia wydarzenia', 'ak-product-set'),
                    'name' => 'ak_event_end_datetime',
                    'type' => 'date_time_picker',
                    'display_format' => 'Y-m-d H:i',
                    'return_format' => 'Y-m-d H:i:s',
                ],
                [
                    'key' => 'field_ak_recruitment_start_datetime',
                    'label' => __('Data i godzina rozpoczęcia rekrutacji', 'ak-product-set'),
                    'name' => 'ak_recruitment_start_datetime',
                    'type' => 'date_time_picker',
                    'display_format' => 'Y-m-d H:i',
                    'return_format' => 'Y-m-d H:i:s',
                ],
                [
                    'key' => 'field_ak_recruitment_end_datetime',
                    'label' => __('Data i godzina zakończenia rekrutacji', 'ak-product-set'),
                    'name' => 'ak_recruitment_end_datetime',
                    'type' => 'date_time_picker',
                    'display_format' => 'Y-m-d H:i',
                    'return_format' => 'Y-m-d H:i:s',
                ],
                [
                    'key' => 'field_ak_event_location',
                    'label' => __('Lokalizacja / Miejsce', 'ak-product-set'),
                    'name' => 'ak_event_location',
                    'type' => 'text',
                ],
            ],
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'product',
                    ],
                ],
            ],
        ]);

        // Group 3: Set Dynamic 3D Pricing Grid (on ak_set CPT)
        $pricing_fields = [
            [
                'key' => 'field_round_1_end_date',
                'label' => __('Koniec Rundy 1 (Early Bird)', 'ak-product-set'),
                'name' => 'round_1_end_date',
                'type' => 'date_time_picker',
                'display_format' => 'Y-m-d H:i',
                'return_format' => 'Y-m-d H:i:s',
            ],
            [
                'key' => 'field_round_2_end_date',
                'label' => __('Koniec Rundy 2 (Regular)', 'ak-product-set'),
                'name' => 'round_2_end_date',
                'type' => 'date_time_picker',
                'display_format' => 'Y-m-d H:i',
                'return_format' => 'Y-m-d H:i:s',
            ],
        ];

        // Populate pricing grid on ak_set: X = 1..10, Y = 1..3, tier = ind, g5, g10
        $tiers = [
            'ind' => __('1 osoba', 'ak-product-set'),
            'g5'  => __('Grupa 5-9 os.', 'ak-product-set'),
            'g10' => __('Grupa 10+ os.', 'ak-product-set'),
        ];

        // Organize pricing fields into Round Tabs (Runda 1, Runda 2, Runda 3) with a 3-column table grid (Ind / G5 / G10)
        for ($y = 1; $y <= 3; $y++) {
            $pricing_fields[] = [
                'key' => 'field_tab_round_' . $y,
                'label' => sprintf(__('Runda %d', 'ak-product-set'), $y),
                'type' => 'tab',
                'placement' => 'top',
            ];

            for ($x = 1; $x <= 10; $x++) {
                foreach ($tiers as $tier_key => $tier_label) {
                    $field_name = sprintf('price_%dw_round%d_%s', $x, $y, $tier_key);
                    $pricing_fields[] = [
                        'key' => 'field_' . $field_name,
                        'label' => sprintf(__('Pakiet %d w. (%s)', 'ak-product-set'), $x, $tier_label),
                        'name' => $field_name,
                        'type' => 'number',
                        'instructions' => sprintf(__('Cena za 1 os. za pakiet %d weekendów w Rundzie %d (%s)', 'ak-product-set'), $x, $y, $tier_label),
                        'min' => 0,
                        'step' => '0.01',
                        'wrapper' => [
                            'width' => '33.33%',
                        ],
                    ];
                }
            }
        }

        acf_add_local_field_group([
            'key' => 'group_ak_set_pricing',
            'title' => __('Cennik Dynamiczny Zestawu (Matrix 3D)', 'ak-product-set'),
            'fields' => $pricing_fields,
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'ak_set',
                    ],
                ],
            ],
        ]);
    }
}
