<?php

/**
 * Registers the ak_set Custom Post Type and all ACF field groups.
 *
 * @package AK_Set
 */

declare(strict_types=1);

namespace AK_Set;

/**
 * Class CPT_Set
 *
 * Handles CPT registration and programmatic ACF field group registration
 * using acf_add_local_field_group() (compatible with ACF Free).
 * No Repeater fields are used.
 */
class CPT_Set
{

	public function register_hooks(): void
	{
		add_action('init',     [$this, 'register_post_type']);
		add_action('acf/init', [$this, 'register_acf_fields']);
	}

	// -------------------------------------------------------------------------
	// Custom Post Type
	// -------------------------------------------------------------------------

	public function register_post_type(): void
	{
		register_post_type(
			'ak_set',
			[
				'labels'        => [
					'name'               => __('Zestawy',               'ak-product-set'),
					'singular_name'      => __('Zestaw',                'ak-product-set'),
					'add_new'            => __('Dodaj nowy',            'ak-product-set'),
					'add_new_item'       => __('Dodaj nowy zestaw',     'ak-product-set'),
					'edit_item'          => __('Edytuj zestaw',         'ak-product-set'),
					'view_item'          => __('Zobacz zestaw',         'ak-product-set'),
					'all_items'          => __('Wszystkie zestawy',     'ak-product-set'),
					'search_items'       => __('Szukaj zestawów',       'ak-product-set'),
					'not_found'          => __('Nie znaleziono zestawów', 'ak-product-set'),
					'not_found_in_trash' => __('Kosz jest pusty',       'ak-product-set'),
				],
				'public'        => false,
				'has_archive'   => false,
				'show_ui'       => true,
				'show_in_menu'  => true,
				'menu_position' => 56,
				'menu_icon'     => 'dashicons-calendar-alt',
				'supports'      => ['title', 'thumbnail', 'editor'],
				'show_in_rest'  => true,
				'capability_type' => 'post',
			]
		);
	}

	// -------------------------------------------------------------------------
	// ACF Field Groups
	// -------------------------------------------------------------------------

	public function register_acf_fields(): void
	{
		if (! function_exists('acf_add_local_field_group')) {
			return;
		}

		$this->register_set_fields();
		$this->register_product_pricing_fields();
	}

	/**
	 * Fields on the ak_set post type:
	 *  - set_products  (Relationship → product IDs)
	 *  - set_has_tshirt (True/False)
	 */
	private function register_set_fields(): void
	{
		acf_add_local_field_group([
			'key'      => 'group_ak_set_options',
			'title'    => 'Opcje zestawu',
			'fields'   => [
				[
					'key'           => 'field_ak_set_products',
					'label'         => 'Produkty w zestawie',
					'name'          => 'set_products',
					'type'          => 'relationship',
					'post_type'     => ['product'],
					'return_format' => 'id',
					'filters'       => ['search'],
					'elements'      => [],
					'min'           => '',
					'max'           => '',
					'instructions'  => 'Wybierz produkty WooCommerce (elementy zestawu) należące do tego zestawu.',
					'required'      => 0,
				],
				[
					'key'           => 'field_ak_set_has_tshirt',
					'label'         => 'Wymagaj T-shirt',
					'name'          => 'set_has_tshirt',
					'type'          => 'true_false',
					'default_value' => 0,
					'ui'            => 1,
					'ui_on_text'    => 'Tak',
					'ui_off_text'   => 'Nie',
					'instructions'  => 'Jeśli włączone, formularz uczestnika będzie zawierał pola rozmiaru i kroju koszulki.',
				],
			],
			'location' => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'ak_set',
					],
				],
			],
			'active'   => true,
		]);
	}

	/**
	 * Pricing fields on WooCommerce products.
	 *
	 * Structure:
	 *   Tab: Daty
	 *     round_1_end_date, round_2_end_date, packages_end_date
	 *   Tab: Indywidualna (1-10)
	 *     price_Xel_roundY_ind  (X=1-10, Y=1-3)
	 *   Tab: Grupowa (5-9)
	 *     price_Xel_roundY_g5
	 *   Tab: Duże grupy (10+)
	 *     price_Xel_roundY_g10
	 *
	 * Note on multi-item prices: the stored value is the TOTAL combined price
	 * for all X items per person. The cart handler divides by X to get the
	 * per-item price.
	 */
	private function register_product_pricing_fields(): void
	{
		$fields = [];

		// ---- Tab: Dates ----
		$fields[] = [
			'key'   => 'field_ak_tab_dates',
			'label' => 'Daty',
			'name'  => '',
			'type'  => 'tab',
		];

		$fields[] = [
			'key'            => 'field_ak_round_1_end_date',
			'label'          => 'Koniec Rundy 1 (Early Bird)',
			'name'           => 'round_1_end_date',
			'type'           => 'date_picker',
			'return_format'  => 'Ymd',
			'display_format' => 'd.m.Y',
			'first_day'      => 1,
			'instructions'   => 'Ostatni dzień obowiązywania ceny z Rundy 1 (Early Bird).',
		];
		$fields[] = [
			'key'            => 'field_ak_round_2_end_date',
			'label'          => 'Koniec Rundy 2',
			'name'           => 'round_2_end_date',
			'type'           => 'date_picker',
			'return_format'  => 'Ymd',
			'display_format' => 'd.m.Y',
			'first_day'      => 1,
			'instructions'   => 'Ostatni dzień obowiązywania ceny z Rundy 2. Po tej dacie obowiązuje Runda 3.',
		];

		// ---- Price tabs: ind / g5 / g10 ----
		$tiers = [
			'ind' => ['label' => 'Indywidualna (1-4 os.)', 'tab_key' => 'field_ak_tab_ind'],
			'g5'  => ['label' => 'Grupowa (5-9 os.)',       'tab_key' => 'field_ak_tab_g5'],
			'g10' => ['label' => 'Duże grupy (10+ os.)',    'tab_key' => 'field_ak_tab_g10'],
		];

		foreach ($tiers as $tier_key => $tier_info) {
			$fields[] = [
				'key'   => $tier_info['tab_key'],
				'label' => $tier_info['label'],
				'name'  => '',
				'type'  => 'tab',
			];

			for ($i = 1; $i <= 10; $i++) {
				for ($r = 1; $r <= 3; $r++) {
					$field_name = "price_{$i}el_round{$r}_{$tier_key}";
					$field_key  = 'field_ak_' . $field_name;
					$label      = ($i === 1)
						? "Cena / Runda {$r}"
						: "Pakiet {$i} szt. / Runda {$r} (CENA CAŁKOWITA)";

					$instructions = ($i > 1)
						? "Wpisz CAŁKOWITĄ kwotę za cały pakiet. System automatycznie podzieli ją przez {$i} szt., aby obliczyć cenę pojedynczego zjazdu w koszyku."
						: '';

					$fields[] = [
						'key'          => $field_key,
						'label'        => $label,
						'name'         => $field_name,
						'type'         => 'number',
						'min'          => 0,
						'max'          => '',
						'step'         => 'any',
						'prepend'      => '',
						'append'       => 'zł',
						'placeholder'  => '0',
						'instructions' => $instructions,
						'wrapper'      => ['width' => '33'],
					];
				}
			}
		}

		acf_add_local_field_group([
			'key'      => 'group_ak_product_pricing',
			'title'    => 'AK Set — Cennik',
			'fields'   => $fields,
			'location' => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'ak_set',
					],
				],
			],
			'active'   => true,
			'position' => 'normal',
			'style'    => 'default',
		]);

		// ---- Product (Weekend) Sales End Date ----
		acf_add_local_field_group([
			'key'      => 'group_ak_product_event_settings',
			'title'    => 'AK Zestaw — Ustawienia sprzedaży',
			'fields'   => [
				[
					'key'            => 'field_ak_event_start_datetime',
					'label'          => 'Data i godzina rozpoczęcia wydarzenia',
					'name'           => 'ak_event_start_datetime',
					'type'           => 'date_time_picker',
					'return_format'  => 'Y-m-d H:i:s',
					'display_format' => 'd.m.Y H:i',
					'first_day'      => 1,
					'required'       => 0,
				],
				[
					'key'            => 'field_ak_event_end_datetime',
					'label'          => 'Data i godzina zakończenia wydarzenia',
					'name'           => 'ak_event_end_datetime',
					'type'           => 'date_time_picker',
					'return_format'  => 'Y-m-d H:i:s',
					'display_format' => 'd.m.Y H:i',
					'first_day'      => 1,
					'required'       => 0,
				],
				[
					'key'            => 'field_ak_recruitment_start_datetime',
					'label'          => 'Data i godzina otwarcia rekrutacji',
					'name'           => 'ak_recruitment_start_datetime',
					'type'           => 'date_time_picker',
					'return_format'  => 'Y-m-d H:i:s',
					'display_format' => 'd.m.Y H:i',
					'first_day'      => 1,
					'required'       => 0,
				],
				[
					'key'            => 'field_ak_recruitment_end_datetime',
					'label'          => 'Data i godzina zakończenia rekrutacji',
					'name'           => 'ak_recruitment_end_datetime',
					'type'           => 'date_time_picker',
					'return_format'  => 'Y-m-d H:i:s',
					'display_format' => 'd.m.Y H:i',
					'first_day'      => 1,
					'required'       => 0,
				],
				[
					'key'            => 'field_ak_event_location',
					'label'          => 'Lokalizacja wydarzenia',
					'name'           => 'ak_event_location',
					'type'           => 'text',
					'required'       => 0,
				],
			],
			'location' => [
				[
					[
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'product',
					],
				],
			],
			'active'   => true,
			'position' => 'side',
			'style'    => 'default',
		]);
	}
}
