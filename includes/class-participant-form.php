<?php

/**
 * Frontend participant form rendering and AJAX handlers.
 *
 * @package AK_Set
 */

declare(strict_types=1);

namespace AK_Set;

/**
 * Class Participant_Form
 *
 * Responsibilities:
 *  - Remove the default WooCommerce add-to-cart form on single product pages
 *    that belong to a Set.
 *  - Render the participant list + add-participant form in their place.
 *  - Provide AJAX endpoints for adding and removing participants.
 *  - Replace the loop "Add to Cart" button with "Wybierz opcje".
 *  - Enqueue assets.
 */
class Participant_Form
{

	public function __construct(private readonly Set_Validator $validator) {}

	/**
	 * Tracks whether we are currently capturing the default add-to-cart form
	 * inside an output buffer (belt-and-suspenders fallback).
	 */
	private bool $suppressing_form = false;

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	public function register_hooks(): void
	{
		// Single product page: take over add-to-cart section.
		add_action('woocommerce_single_product_summary', [$this, 'maybe_replace_add_to_cart'], 5);

		// Belt-and-suspenders: if remove_action() doesn't catch it (e.g. theme overrides),
		// capture the default form in an output buffer and discard it.
		add_action('woocommerce_before_add_to_cart_form', [$this, 'suppress_form_start']);
		add_action('woocommerce_after_add_to_cart_form',  [$this, 'suppress_form_end']);

		// Shop / archive loops: replace button.
		add_filter('woocommerce_loop_add_to_cart_link', [$this, 'change_loop_button'], 10, 3);

		// Assets.
		add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

		// AJAX: add participant.
		add_action('wp_ajax_ak_add_participant',        [$this, 'ajax_add_participant']);
		add_action('wp_ajax_nopriv_ak_add_participant', [$this, 'ajax_add_participant']);

		// AJAX: remove participant.
		add_action('wp_ajax_ak_remove_participant',        [$this, 'ajax_remove_participant']);
		add_action('wp_ajax_nopriv_ak_remove_participant', [$this, 'ajax_remove_participant']);

		// Hide set products from catalog, searches, queries, and related products
		add_filter('woocommerce_product_is_visible',  [$this, 'hide_set_products_from_catalog'], 10, 2);
		add_action('woocommerce_product_query',       [$this, 'exclude_set_products_from_wc_query'], 10, 1);
		add_action('pre_get_posts',                   [$this, 'exclude_set_products_from_search'], 10, 1);
		add_filter('woocommerce_related_products',    [$this, 'exclude_set_products_from_related'], 10, 3);

		// Prevent single product access
		add_action('template_redirect', [$this, 'redirect_single_set_product']);

		// Register shortcode
		add_shortcode('ak_set', [$this, 'render_shortcode']);
	}

	// -------------------------------------------------------------------------
	// Single product page
	// -------------------------------------------------------------------------

	/**
	 * Hooked into woocommerce_single_product_summary at priority 5.
	 * If the current product is a Set product, removes the default add-to-cart
	 * action and injects our custom section at the same priority (30).
	 * Falls back to wc_get_product() when the global $product is not yet set.
	 */
	public function maybe_replace_add_to_cart(): void
	{
		global $product;

		if (! $product instanceof \WC_Product) {
			$product = wc_get_product(get_the_ID());
		}

		if (! $product instanceof \WC_Product) {
			return;
		}

		if (! $this->validator->is_set_product($product->get_id())) {
			return;
		}

		remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
		add_action('woocommerce_single_product_summary', [$this, 'render_participant_section'], 30);
	}

	/**
	 * Output-buffer fallback — START.
	 * If the default WC form is rendered despite our remove_action() call,
	 * this captures its output so it can be discarded.
	 * Only activates on Set product pages.
	 */
	public function suppress_form_start(): void
	{
		global $product;

		if (! $product instanceof \WC_Product) {
			$product = wc_get_product(get_the_ID());
		}

		if ($product instanceof \WC_Product && $this->validator->is_set_product($product->get_id())) {
			ob_start();
			$this->suppressing_form = true;
		}
	}

	/**
	 * Output-buffer fallback — END.
	 * Discards the captured form output.
	 */
	public function suppress_form_end(): void
	{
		if ($this->suppressing_form) {
			ob_end_clean();
			$this->suppressing_form = false;
		}
	}

	/**
	 * Formats the raw price rule string into a human-readable description.
	 */
	private function format_price_rule(string $rule): string
	{
		if ($rule === 'fallback_woocommerce_price') {
			return __('Cena standardowa', 'ak-product-set');
		}

		if (preg_match('/^price_(\d+)el_round(\d+)_([a-z0-9]+)$/', $rule, $matches)) {
			$qty   = (int) $matches[1];
			$round = (int) $matches[2];
			$tier  = $matches[3];

			$tier_names = [
				'ind' => 'Indywidualnie',
				'g5'  => 'Grupa (5-9 os.)',
				'g10' => 'Grupa (10+ os.)',
			];
			$tier_name = $tier_names[$tier] ?? $tier;

			$round_names = [
				1 => 'I tura',
				2 => 'II tura',
				3 => 'III tura',
			];
			$round_name = $round_names[$round] ?? "Tura {$round}";
			
			$qty_name = ($qty === 1) ? '1 zjazd' : "pakiet {$qty}-el.";

			return sprintf('%s, %s, %s', $tier_name, $qty_name, $round_name);
		}

		return $rule;
	}

	/**
	 * Renders the full participant section: list + form.
	 */
	public function render_participant_section(?\WC_Product $passed_product = null): void
	{
		if ($passed_product instanceof \WC_Product) {
			$product = $passed_product;
		} else {
			global $product;
			if (! $product instanceof \WC_Product) {
				return;
			}
		}

		$product_id = $product->get_id();
		$sets       = $this->validator->get_sets_for_product($product_id);

		if (empty($sets)) {
			return;
		}

		$set_id          = $sets[0];
		$has_tshirt      = $this->validator->set_has_tshirt($set_id);
		$participants    = $this->get_participants_for_product($product_id);
		$stock_qty         = $product->get_stock_quantity();
		$cart_count        = count($participants);
		$is_exhausted      = ($stock_qty !== null && $cart_count >= (int) $stock_qty);
		$sales_blocked_msg = $this->validator->get_sales_block_message($product_id);

?>
		<div class="ak-product-set-section"
			data-product-id="<?php echo esc_attr((string) $product_id); ?>">

			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->get_participant_list_html($participants);
			?>

			<?php if ($sales_blocked_msg) : ?>
				<div class="ak-sales-blocked-msg" style="padding: 16px; background: #fff3cd; color: #856404; border-radius: 6px; margin-top: 16px; border: 1px solid #ffeeba;">
					<?php echo esc_html($sales_blocked_msg); ?>
				</div>
			<?php else : ?>
				<div class="ak-form-wrapper">

				<form class="ak-participant-form<?php echo $is_exhausted ? ' ak-hidden' : ''; ?>"
					novalidate>

					<h4 class="ak-form-title">
						<?php esc_html_e('Dodaj uczestnika', 'ak-product-set'); ?>
					</h4>

					<div class="ak-form-field">
						<label for="ak-participant-name-<?php echo esc_attr((string) $product_id); ?>">
							<?php esc_html_e('Imię i nazwisko', 'ak-product-set'); ?>
							<span class="required" aria-hidden="true">*</span>
						</label>
						<input
							type="text"
							id="ak-participant-name-<?php echo esc_attr((string) $product_id); ?>"
							name="name"
							required
							autocomplete="off"
							placeholder="<?php esc_attr_e('Wpisz imię i nazwisko', 'ak-product-set'); ?>">
					</div>

					<?php if ($has_tshirt) : ?>
						<div class="ak-form-row">
							<div class="ak-form-field">
								<label for="ak-participant-size-<?php echo esc_attr((string) $product_id); ?>">
									<?php esc_html_e('Rozmiar koszulki', 'ak-product-set'); ?>
								</label>
								<select id="ak-participant-size-<?php echo esc_attr((string) $product_id); ?>" name="size">
									<?php foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $size) : ?>
										<option value="<?php echo esc_attr($size); ?>">
											<?php echo esc_html($size); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="ak-form-field">
								<label for="ak-participant-cut-<?php echo esc_attr((string) $product_id); ?>">
									<?php esc_html_e('Krój koszulki', 'ak-product-set'); ?>
								</label>
								<select id="ak-participant-cut-<?php echo esc_attr((string) $product_id); ?>" name="cut">
									<option value="Damska"><?php esc_html_e('Damska', 'ak-product-set'); ?></option>
									<option value="Męska"><?php esc_html_e('Męska', 'ak-product-set'); ?></option>
								</select>
							</div>
						</div>
					<?php endif; ?>

					<div class="ak-form-actions">
						<button type="submit" class="button alt ak-btn-add">
							<?php esc_html_e('Dodaj uczestnika', 'ak-product-set'); ?>
						</button>
					</div>

					<div class="ak-form-notice ak-hidden" role="alert"></div>

				</form>

				<p class="ak-stock-exhausted<?php echo $is_exhausted ? '' : ' ak-hidden'; ?>">
					<?php esc_html_e('Wszystkie miejsca są zajęte. Usuń uczestnika, aby dodać innego.', 'ak-product-set'); ?>
				</p>

			</div><!-- .ak-form-wrapper -->
			<?php endif; ?>

		</div><!-- .ak-product-set-section -->
<?php
	}

	// -------------------------------------------------------------------------
	// Loop button
	// -------------------------------------------------------------------------

	/**
	 * Replace loop "Add to Cart" with "Wybierz opcje" → single product page.
	 *
	 * @param string      $button  Current button HTML.
	 * @param \WC_Product $product The product.
	 * @param array       $args    Button args.
	 * @return string Modified button HTML.
	 */
	public function change_loop_button(string $button, \WC_Product $product, array $args): string
	{
		if (! $this->validator->is_set_product($product->get_id())) {
			return $button;
		}

		return sprintf(
			'<a href="%s" class="button ak-btn-choose-options">%s</a>',
			esc_url(get_permalink($product->get_id())),
			esc_html__('Wybierz opcje', 'ak-product-set')
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_scripts(): void
	{
		if (! is_product()) {
			return;
		}

		global $product;
		if (! $product instanceof \WC_Product) {
			$product = wc_get_product(get_the_ID());
		}
		if (! $product || ! $this->validator->is_set_product($product->get_id())) {
			return;
		}

		wp_enqueue_style(
			'ak-set-form',
			AK_SET_URL . 'assets/ak-set-form.css',
			[],
			AK_SET_VERSION
		);

		wp_enqueue_script(
			'ak-set-form',
			AK_SET_URL . 'assets/ak-set-form.js',
			[],
			AK_SET_VERSION,
			true
		);

		wp_localize_script('ak-set-form', 'ak_set_params', [
			'ajax_url'          => admin_url('admin-ajax.php'),
			'nonce'             => wp_create_nonce('ak_set_action'),
			'btn_add_label'     => __('Dodaj uczestnika',                       'ak-product-set'),
			'btn_loading_label' => __('Dodawanie…',                             'ak-product-set'),
			'btn_remove_label'  => '✕',
			'stock_exhausted'   => __('Wszystkie miejsca są zajęte. Usuń uczestnika, aby dodać innego.', 'ak-product-set'),
			'error_generic'     => __('Wystąpił błąd. Spróbuj ponownie.',        'ak-product-set'),
		]);
	}

	// -------------------------------------------------------------------------
	// AJAX: add participant
	// -------------------------------------------------------------------------

	public function ajax_add_participant(): void
	{
		check_ajax_referer('ak_set_action', 'nonce');

		$product_id = (int) ($_POST['product_id'] ?? 0);

		if (! $product_id) {
			wp_send_json_error(['message' => __('Nieprawidłowe żądanie.', 'ak-product-set')]);
		}

		if (! $this->validator->is_set_product($product_id)) {
			wp_send_json_error(['message' => __('Nieprawidłowy produkt.', 'ak-product-set')]);
		}

		$product = wc_get_product($product_id);
		if (! $product) {
			wp_send_json_error(['message' => __('Nie znaleziono produktu.', 'ak-product-set')]);
		}

		if ($msg = $this->validator->get_sales_block_message($product_id)) {
			wp_send_json_error(['message' => $msg]);
		}

		// Parse items to add (support single or batch)
		$items_to_add = [];
		if (isset($_POST['participants'])) {
			$raw = $_POST['participants'];
			if (is_string($raw)) {
				$decoded = json_decode(wp_unslash($raw), true);
				if (is_array($decoded)) {
					$items_to_add = $decoded;
				}
			} elseif (is_array($raw)) {
				$items_to_add = $raw;
			}
		} else {
			$name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
			$size = sanitize_text_field(wp_unslash($_POST['size'] ?? ''));
			$cut  = sanitize_text_field(wp_unslash($_POST['cut']  ?? ''));
			if ($name) {
				$items_to_add[] = [
					'name' => $name,
					'size' => $size,
					'cut'  => $cut,
				];
			}
		}

		if (empty($items_to_add)) {
			wp_send_json_error(['message' => __('Podaj imię i nazwisko uczestnika.', 'ak-product-set')]);
		}

		// Stock check.
		$stock_qty    = $product->get_stock_quantity();
		$current      = $this->get_participants_for_product($product_id);
		$cart_count   = count($current);
		$to_add_count = count($items_to_add);

		if ($stock_qty !== null && ($cart_count + $to_add_count) > (int) $stock_qty) {
			wp_send_json_error(['message' => __('Brak wystarczającej liczby wolnych miejsc dla tego produktu.', 'ak-product-set')]);
		}

		$sets   = $this->validator->get_sets_for_product($product_id);
		$set_id = $sets[0] ?? 0;

		$added_any = false;
		foreach ($items_to_add as $item_raw) {
			$p_name = sanitize_text_field($item_raw['name'] ?? '');
			if (! $p_name) {
				continue;
			}

			$participant_data = ['name' => $p_name];
			if (! empty($item_raw['size'])) {
				$participant_data['size'] = sanitize_text_field($item_raw['size']);
			}
			if (! empty($item_raw['cut'])) {
				$participant_data['cut'] = sanitize_text_field($item_raw['cut']);
			}

			$slot_id = $this->generate_uuid();
			$cart_item_data = [
				'_ak_participant_data' => $participant_data,
				'_ak_slot_id'          => $slot_id,
				'_ak_set_id'           => $set_id,
			];

			$result = WC()->cart->add_to_cart($product_id, 1, 0, [], $cart_item_data);
			if ($result) {
				$added_any = true;
			}
		}

		if (! $added_any) {
			$notices = wc_get_notices('error');
			$message = ! empty($notices)
				? wp_strip_all_tags($notices[0]['notice'])
				: __('Nie udało się dodać uczestnika do koszyka.', 'ak-product-set');
			wc_clear_notices();
			wp_send_json_error(['message' => $message]);
		}

		WC()->cart->calculate_totals();

		$product_ids_to_update = [$product_id];
		foreach (WC()->cart->get_cart() as $item) {
			if (!empty($item['_ak_set_id']) && $item['_ak_set_id'] == $set_id) {
				$product_ids_to_update[] = (int) $item['product_id'];
			}
		}
		$product_ids_to_update = array_unique($product_ids_to_update);

		$sections_data = [];
		foreach ($product_ids_to_update as $pid) {
			$p = wc_get_product($pid);
			$sq = $p ? $p->get_stock_quantity() : null;
			$participants = $this->get_participants_for_product($pid);
			$count = count($participants);
			$sections_data[$pid] = [
				'list_html'    => $this->get_participant_list_html($participants),
				'is_exhausted' => ($sq !== null && $count >= (int) $sq),
				'cart_count'   => $count,
			];
		}

		wp_send_json_success([
			'list_html'         => $sections_data[$product_id]['list_html'] ?? '',
			'is_exhausted'      => $sections_data[$product_id]['is_exhausted'] ?? false,
			'cart_count'        => $sections_data[$product_id]['cart_count'] ?? 0,
			'sections_data'     => $sections_data,
			'global_cart_total' => wc_price(WC()->cart->get_total('edit')),
			'is_cart_empty'     => WC()->cart->is_empty(),
		]);
	}

	// -------------------------------------------------------------------------
	// AJAX: remove participant
	// -------------------------------------------------------------------------

	public function ajax_remove_participant(): void
	{
		check_ajax_referer('ak_set_action', 'nonce');

		$cart_item_key = sanitize_text_field(wp_unslash($_POST['cart_item_key'] ?? ''));
		$product_id    = (int) ($_POST['product_id'] ?? 0);

		if (! $cart_item_key || ! $product_id) {
			wp_send_json_error(['message' => __('Nieprawidłowe żądanie.', 'ak-product-set')]);
		}

		$cart      = WC()->cart;
		$cart_item = $cart->get_cart_item($cart_item_key);

		if (! $cart_item || (int) $cart_item['product_id'] !== $product_id) {
			wp_send_json_error(['message' => __('Nie znaleziono uczestnika w koszyku.', 'ak-product-set')]);
		}

		$set_id = (int) ($cart_item['_ak_set_id'] ?? 0);
		$cart->remove_cart_item($cart_item_key);
		WC()->cart->calculate_totals();

		$product_ids_to_update = [$product_id];
		foreach ($cart->get_cart() as $item) {
			if (!empty($item['_ak_set_id']) && $item['_ak_set_id'] == $set_id) {
				$product_ids_to_update[] = (int) $item['product_id'];
			}
		}
		$product_ids_to_update = array_unique($product_ids_to_update);

		$sections_data = [];
		foreach ($product_ids_to_update as $pid) {
			$p = wc_get_product($pid);
			$sq = $p ? $p->get_stock_quantity() : null;
			$participants = $this->get_participants_for_product($pid);
			$count = count($participants);
			$sections_data[$pid] = [
				'list_html'    => $this->get_participant_list_html($participants),
				'is_exhausted' => ($sq !== null && $count >= (int) $sq),
				'cart_count'   => $count,
			];
		}

		wp_send_json_success([
			'list_html'         => $sections_data[$product_id]['list_html'] ?? '',
			'is_exhausted'      => $sections_data[$product_id]['is_exhausted'] ?? false,
			'cart_count'        => $sections_data[$product_id]['cart_count'] ?? 0,
			'sections_data'     => $sections_data,
			'global_cart_total' => wc_price(WC()->cart->get_total('edit')),
			'is_cart_empty'     => WC()->cart->is_empty(),
		]);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get all cart items for a product that have participant data.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return array<string, mixed[]> Keyed by cart item key.
	 */
	public function get_participants_for_product(int $product_id): array
	{
		$participants = [];

		if (! WC()->cart || ! WC()->session) {
			return $participants;
		}

		foreach (WC()->cart->get_cart() as $key => $item) {
			if (
				(int) $item['product_id'] === $product_id
				&& isset($item['_ak_participant_data'])
			) {
				$participants[$key] = $item;
			}
		}

		return $participants;
	}

	/**
	 * Build the HTML for the participant list `<div>`.
	 * Used both in PHP template and AJAX responses so the server always renders
	 * the HTML — no JS templating needed.
	 *
	 * @param array<string, mixed[]> $participants Keyed by cart item key.
	 * @return string Safe HTML string.
	 */
	public function get_participant_list_html(array $participants): string
	{
		if (empty($participants)) {
			return '<div class="ak-participant-list ak-participant-list--empty">'
				. '<p class="ak-no-participants">'
				. esc_html__('Brak uczestników. Dodaj pierwszego uczestnika poniżej.', 'ak-product-set')
				. '</p></div>';
		}

		$html  = '<div class="ak-participant-list">';
		$html .= '<h4 class="ak-list-title">' . esc_html__('Uczestnicy w koszyku', 'ak-product-set') . '</h4>';
		$html .= '<ul class="ak-participants-ul">';

		$total_price = 0.0;

		foreach ($participants as $key => $item) {
			$total_price += (float) $item['data']->get_price();

			$data  = $item['_ak_participant_data'];
			$name  = esc_html($data['name'] ?? '');
			$size  = isset($data['size']) ? esc_html($data['size']) : '';
			$cut   = isset($data['cut'])  ? esc_html($data['cut'])  : '';

			$extras = array_filter([$size, $cut]);
			$extra  = $extras ? ' <span class="ak-participant-extras">(' . implode(', ', $extras) . ')</span>' : '';

			$price_html = wc_price($item['data']->get_price());
			$formatted_rule = !empty($item['_ak_price_rule']) ? $this->format_price_rule($item['_ak_price_rule']) : '';
			$rule_desc  = $formatted_rule ? ' <small class="ak-participant-rule-desc">(' . esc_html($formatted_rule) . ')</small>' : '';

			$html .= '<li class="ak-participant-item" data-name="' . esc_attr($name) . '" data-size="' . esc_attr($size) . '" data-cut="' . esc_attr($cut) . '">';
			$html .= '<span class="ak-participant-name">' . $name . $extra . '<br><span class="ak-participant-price">' . $price_html . $rule_desc . '</span></span>';
			$html .= '<button type="button" class="ak-remove-btn" '
				. 'data-key="' . esc_attr($key) . '" '
				. 'aria-label="' . esc_attr__('Usuń uczestnika', 'ak-product-set') . '">'
				. esc_html('✕')
				. '</button>';
			$html .= '</li>';
		}

		$html .= '</ul>';



		$html .= '</div>';

		return $html;
	}

	/**
	 * Generate a RFC 4122 UUID v4.
	 */
	private function generate_uuid(): string
	{
		$data    = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4.
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant bits.

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	public function hide_set_products_from_catalog(bool $visible, int $product_id): bool
	{
		if ($this->validator->is_set_product($product_id)) {
			return false;
		}
		return $visible;
	}

	/**
	 * Exclude Set products from WooCommerce shop and archive queries.
	 */
	public function exclude_set_products_from_wc_query(\WP_Query $q): void
	{
		$set_pids = $this->validator->get_all_set_product_ids();
		if (! empty($set_pids)) {
			$existing = (array) ($q->get('post__not_in') ?: []);
			$q->set('post__not_in', array_unique(array_merge($existing, $set_pids)));
		}
	}

	/**
	 * Exclude Set products from main site search queries.
	 */
	public function exclude_set_products_from_search(\WP_Query $q): void
	{
		if (is_admin() || ! $q->is_main_query() || ! $q->is_search()) {
			return;
		}

		$set_pids = $this->validator->get_all_set_product_ids();
		if (! empty($set_pids)) {
			$existing = (array) ($q->get('post__not_in') ?: []);
			$q->set('post__not_in', array_unique(array_merge($existing, $set_pids)));
		}
	}

	/**
	 * Exclude Set products from WooCommerce related products output.
	 */
	public function exclude_set_products_from_related(array $related_posts, int $product_id, array $args): array
	{
		$set_pids = $this->validator->get_all_set_product_ids();
		if (empty($set_pids)) {
			return $related_posts;
		}
		return array_values(array_diff($related_posts, $set_pids));
	}

	public function redirect_single_set_product(): void
	{
		// Prevent access to single Set post type
		if (is_singular('ak_set')) {
			wp_safe_redirect(home_url());
			exit;
		}

		// Prevent access to single WooCommerce product if it belongs to a set
		if (is_product()) {
			$product_id = get_the_ID();
			if ($this->validator->is_set_product($product_id)) {
				wp_safe_redirect(wc_get_page_permalink('shop') ?: home_url());
				exit;
			}
		}
	}

	public function render_shortcode(array|string $atts): string
	{
		$atts = shortcode_atts(['id' => 0], (array) $atts);
		$set_id = (int) $atts['id'];

		if (!$set_id) {
			return '';
		}

		$product_ids = get_field('set_products', $set_id);
		if (empty($product_ids)) {
			return '';
		}

		// Enqueue scripts & styles for this shortcode
		wp_enqueue_style('ak-set-form', AK_SET_URL . 'assets/ak-set-form.css', [], AK_SET_VERSION);
		wp_enqueue_script('ak-set-form', AK_SET_URL . 'assets/ak-set-form.js', [], AK_SET_VERSION, true);
		wp_localize_script('ak-set-form', 'ak_set_params', [
			'ajax_url'          => admin_url('admin-ajax.php'),
			'nonce'             => wp_create_nonce('ak_set_action'),
			'btn_add_label'     => __('Dodaj uczestnika', 'ak-product-set'),
			'btn_loading_label' => __('Dodawanie…', 'ak-product-set'),
			'btn_remove_label'  => '✕',
			'stock_exhausted'   => __('Wszystkie miejsca są zajęte. Usuń uczestnika, aby dodać innego.', 'ak-product-set'),
			'error_generic'     => __('Wystąpił błąd. Spróbuj ponownie.', 'ak-product-set'),
		]);

		ob_start();
		echo '<div class="ak-set-shortcode-wrapper">';

		// Render Pricing Table for active and future rounds
		$max_elements = max(1, count($product_ids));
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->render_pricing_table($set_id, $max_elements);

		foreach ($product_ids as $pid) {
			$product = wc_get_product((int) $pid);
			if (!$product || $product->get_status() !== 'publish') {
				continue;
			}

			echo '<div class="ak-box-card">';
			
			// Box Header: Image + Info
			echo '<div class="ak-box-header">';
			
			// Image
			$image_id = $product->get_image_id();
			if ($image_id) {
				echo '<div class="ak-box-image">';
				echo wp_get_attachment_image((int) $image_id, 'medium');
				echo '</div>';
			}
			
			// Info (Title + Short Description + Full Description)
			echo '<div class="ak-box-info">';
			echo '<h3 class="ak-box-title">' . esc_html($product->get_title()) . '</h3>';
			$short_desc = $product->get_short_description();
			if ($short_desc) {
				echo '<div class="ak-box-desc" style="font-size: 12px;">' . apply_filters('woocommerce_short_description', $short_desc) . '</div>';
			}
			
			$full_desc = $product->get_description();
			if ($full_desc) {
				echo '<div class="ak-box-full-desc" style="font-size: 12px;">' . apply_filters('the_content', $full_desc) . '</div>';
			}
			
			echo '</div>'; // End .ak-box-info
			echo '</div>'; // End .ak-box-header

			// Display the new event fields
			$pid = $product->get_id();
			
			$format_dt = function ($val) {
				if (!$val) return '';
				return date('d.m.Y, H:i', strtotime((string)$val));
			};

			$event_start_dt = $format_dt(get_field('ak_event_start_datetime', $pid));
			$event_end_dt   = $format_dt(get_field('ak_event_end_datetime', $pid));
			$recr_start_dt  = $format_dt(get_field('ak_recruitment_start_datetime', $pid));
			$recr_end_dt    = $format_dt(get_field('ak_recruitment_end_datetime', $pid));
			$location       = get_field('ak_event_location', $pid);

			if ($event_start_dt || $event_end_dt || $recr_start_dt || $recr_end_dt || $location) {
				echo '<div class="ak-event-meta-info" style="margin: 0 0 24px 0; display: flex; flex-wrap: wrap; gap: 8px;">';
				$chip_style = 'padding: 6px 14px; background: #f8fafc; border-radius: 20px; font-size: 11px; color: #475569; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; white-space: nowrap;';
				$strong_style = 'color: #0f172a; margin-right: 6px; font-weight: 600;';
				if ($event_start_dt) echo '<div class="ak-meta-chip" style="' . $chip_style . '"><strong style="' . $strong_style . '">' . esc_html__('Rozpoczęcie wydarzenia:', 'ak-product-set') . '</strong> ' . esc_html($event_start_dt) . '</div>';
				if ($event_end_dt)   echo '<div class="ak-meta-chip" style="' . $chip_style . '"><strong style="' . $strong_style . '">' . esc_html__('Zakończenie wydarzenia:', 'ak-product-set') . '</strong> ' . esc_html($event_end_dt) . '</div>';
				if ($recr_start_dt)  echo '<div class="ak-meta-chip" style="' . $chip_style . '"><strong style="' . $strong_style . '">' . esc_html__('Otwarcie rekrutacji:', 'ak-product-set') . '</strong> ' . esc_html($recr_start_dt) . '</div>';
				if ($recr_end_dt)    echo '<div class="ak-meta-chip" style="' . $chip_style . '"><strong style="' . $strong_style . '">' . esc_html__('Zakończenie rekrutacji:', 'ak-product-set') . '</strong> ' . esc_html($recr_end_dt) . '</div>';
				if ($location)       echo '<div class="ak-meta-chip" style="' . $chip_style . ' white-space: normal;"><strong style="' . $strong_style . '">' . esc_html__('Lokalizacja:', 'ak-product-set') . '</strong> ' . esc_html($location) . '</div>';
				echo '</div>';
			}

			// Box Body: Participant list and form
			echo '<div class="ak-box-body">';
			$this->render_participant_section($product);
			echo '</div>';
			
			echo '</div>'; // End .ak-box-card
		}

		echo '</div>'; // End .ak-set-shortcode-wrapper

		// Floating Cart Total Bar
		$cart = WC()->cart;
		$is_empty = (!$cart || $cart->is_empty());
		$cart_total = $cart ? $cart->get_total() : wc_price(0);

		echo '<div class="ak-floating-cart-bar' . ($is_empty ? ' ak-hidden' : '') . '">';
		echo '<div class="ak-floating-cart-inner">';
		echo '<div class="ak-floating-cart-total">';
		echo '<strong>' . esc_html__('Suma koszyka:', 'ak-product-set') . '</strong> ';
		echo '<span class="ak-global-cart-total-value">' . $cart_total . '</span>';
		echo '</div>';
		echo '<a href="' . esc_url(wc_get_checkout_url()) . '" class="button alt ak-btn-checkout">' . esc_html__('Przejdź do kasy', 'ak-product-set') . '</a>';
		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * Render the pricing table for active (current and future) rounds.
	 *
	 * @param int $set_id Set post ID.
	 * @param int $max_elements Maximum number of package elements in this set.
	 * @return string HTML table string.
	 */
	public function render_pricing_table(int $set_id, int $max_elements): string
	{
		$today  = date('Ymd');
		$r1_raw = (string) (get_field('round_1_end_date', $set_id) ?: '');
		$r2_raw = (string) (get_field('round_2_end_date', $set_id) ?: '');

		$current_round = $this->validator->determine_round($set_id, $today);
		$max_round     = $this->validator->get_max_round($set_id);

		$rounds_info = [
			1 => [
				'title' => __('I Tura (Early Bird)', 'ak-product-set'),
				'end'   => $r1_raw ? date('d.m.Y', strtotime($r1_raw)) : '',
			],
			2 => [
				'title' => __('II Tura', 'ak-product-set'),
				'end'   => $r2_raw ? date('d.m.Y', strtotime($r2_raw)) : '',
			],
			3 => [
				'title' => __('III Tura', 'ak-product-set'),
				'end'   => '',
			],
		];

		$tiers = [
			'ind' => __('Indywidualnie<br><small style="font-weight: 500; color: #64748b;">(1-4 os.)</small>', 'ak-product-set'),
			'g5'  => __('Grupa<br><small style="font-weight: 500; color: #64748b;">(5-9 os.)</small>', 'ak-product-set'),
			'g10' => __('Duże grupy<br><small style="font-weight: 500; color: #64748b;">(10+ os.)</small>', 'ak-product-set'),
		];

		$html = '<div class="ak-pricing-table-wrapper">';
		$html .= '<h3 class="ak-pricing-table-title">' . esc_html__('Cennik i Tury Zgłoszeń', 'ak-product-set') . '</h3>';

		$has_any_round = false;

		for ($r = $current_round; $r <= $max_round; $r++) {
			$round_info = $rounds_info[$r];
			$is_current = ($r === $current_round);

			$rows_html = '';
			$has_prices_in_round = false;

			for ($i = 1; $i <= $max_elements; $i++) {
				$row_cells = '';
				$row_has_price = false;

				foreach ($tiers as $tier_key => $tier_label) {
					[$raw_price] = $this->validator->get_price_cascade($set_id, "price_{$i}el_round", $tier_key, $r);

					if ($raw_price > 0.0) {
						$row_has_price = true;
						$has_prices_in_round = true;
						$price_formatted = wc_price($raw_price);
						if ($i > 1) {
							$per_item = wc_price($raw_price / $i);
							$price_formatted .= '<br><small class="ak-per-item-hint">(' . sprintf(__('%s / zjazd', 'ak-product-set'), $per_item) . ')</small>';
						}
						$row_cells .= '<td class="ak-pt-cell">' . $price_formatted . '</td>';
					} else {
						$row_cells .= '<td class="ak-pt-cell ak-pt-cell--empty">—</td>';
					}
				}

				if ($row_has_price) {
					$label = ($i === 1)
						? __('1 zjazd', 'ak-product-set')
						: sprintf(_n('%d zjazd', '%d zjazdy', $i, 'ak-product-set'), $i);

					if ($i >= 5) {
						$label = sprintf(__('%d zjazdów', 'ak-product-set'), $i);
					}

					$rows_html .= '<tr class="ak-pt-row">';
					$rows_html .= '<td class="ak-pt-label">' . esc_html($label) . '</td>';
					$rows_html .= $row_cells;
					$rows_html .= '</tr>';
				}
			}

			if (! $has_prices_in_round) {
				continue;
			}

			$has_any_round = true;

			$date_str = $round_info['end']
				? ' <span class="ak-round-end-date">(' . sprintf(__('obowiązuje do %s', 'ak-product-set'), esc_html($round_info['end'])) . ')</span>'
				: '';

			$badge = $is_current
				? '<span class="ak-round-badge ak-round-badge--active">' . esc_html__('Aktualna tura', 'ak-product-set') . '</span>'
				: '';

			$html .= '<div class="ak-round-block' . ($is_current ? ' ak-round-block--active' : '') . '">';
			$html .= '<div class="ak-round-header">';
			$html .= '<div class="ak-round-title">' . esc_html($round_info['title']) . $date_str . '</div>';
			$html .= $badge;
			$html .= '</div>';

			$html .= '<div class="ak-pt-table-responsive">';
			$html .= '<table class="ak-pt-table">';
			$html .= '<thead><tr>';
			$html .= '<th>' . esc_html__('Pakiet', 'ak-product-set') . '</th>';
			foreach ($tiers as $tier_html) {
				$html .= '<th>' . $tier_html . '</th>';
			}
			$html .= '</tr></thead>';
			$html .= '<tbody>' . $rows_html . '</tbody>';
			$html .= '</table>';
			$html .= '</div>';
			$html .= '</div>';
		}

		$html .= '</div>';

		return $has_any_round ? $html : '';
	}
}

