<?php

namespace AK_Set\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Restricts users with the ak_roster_manager role to the roster page, read-only order views,
 * and management (create, edit, remove) of courses (ak_set), products, and coupons.
 * Redirects unauthorized admin pages, strips unrelated admin menu nodes, and blocks order mutations.
 */
class Roster_Access_Guard {

    public function init(): void {
        add_action('admin_init',                         [$this, 'enforce_roster_only_access'], 1);
        add_action('admin_init',                         [$this, 'prevent_order_mutations']);
        add_action('admin_menu',                         [$this, 'strip_menu_for_manager'], 999);
        add_action('wp_before_admin_bar_render',         [$this, 'strip_admin_bar_for_manager']);
        add_filter('woocommerce_prevent_admin_access',   [$this, 'allow_admin_access']);
        add_filter('show_admin_bar',                     [$this, 'force_show_admin_bar_for_manager'], 9999);
        add_filter('map_meta_cap',                       [$this, 'filter_map_meta_cap'], 10, 4);
        add_filter('user_has_cap',                       [$this, 'filter_user_caps'], 10, 4);
        add_filter('bulk_actions-edit-shop_order',       [$this, 'remove_order_bulk_actions']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'remove_order_bulk_actions']);
        add_action('admin_head',                         [$this, 'inject_read_only_order_styles']);
        add_action('admin_notices',                      [$this, 'show_read_only_notice']);
        add_action('woocommerce_before_order_object_save', [$this, 'block_order_save']);
    }

    /**
     * Prevent WooCommerce from redirecting roster managers to /my-account/
     */
    public function allow_admin_access(bool $prevent_access): bool {
        if (current_user_can('ak_view_roster')) {
            return false;
        }
        return $prevent_access;
    }

    /**
     * Force-enable the top admin bar on the frontend for logged-in roster managers.
     */
    public function force_show_admin_bar_for_manager(bool $show): bool {
        if (is_user_logged_in() && $this->is_roster_manager()) {
            return true;
        }
        return $show;
    }

    /**
     * Map meta capabilities (edit_shop_order, read_shop_order) for roster managers
     * to 'ak_view_roster' so WooCommerce HPOS permission checks pass smoothly.
     */
    public function filter_map_meta_cap(array $caps, string $cap, int $user_id, array $args): array {
        if (!in_array($cap, ['edit_shop_order', 'read_shop_order', 'edit_post', 'read_post'], true)) {
            return $caps;
        }

        $user = function_exists('get_userdata') ? get_userdata($user_id) : null;
        if (!$user) {
            return $caps;
        }

        if (in_array('administrator', (array) $user->roles, true)) {
            return $caps;
        }

        $is_roster_user = in_array('ak_roster_manager', (array) $user->roles, true)
            || (function_exists('user_can') && user_can($user, 'ak_view_roster'));

        if (!$is_roster_user) {
            return $caps;
        }

        if (in_array($cap, ['edit_shop_order', 'read_shop_order'], true)) {
            return ['ak_view_roster'];
        }

        // For edit_post / read_post, only map to ak_view_roster if the target post is a shop_order
        if (!empty($args[0]) && function_exists('get_post_type')) {
            $object_id = (int) $args[0];
            $post_type = get_post_type($object_id);
            if ($post_type === 'shop_order') {
                return ['ak_view_roster'];
            }
        }

        return $caps;
    }

    /**
     * Dynamically grant order reading, product, coupon, course and media capabilities to roster manager users.
     */
    public function filter_user_caps(array $allcaps, array $caps, array $args, \WP_User $user): array {
        if (in_array('administrator', (array) $user->roles, true)) {
            return $allcaps;
        }

        $is_roster_user = in_array('ak_roster_manager', (array) $user->roles, true)
            || !empty($allcaps['ak_view_roster']);

        if ($is_roster_user) {
            $granted_caps = [
                // Orders (read-only view)
                'edit_shop_order',
                'read_shop_order',
                'delete_shop_order',
                'edit_shop_orders',
                'read_shop_orders',
                'delete_shop_orders',
                'edit_others_shop_orders',
                'edit_published_shop_orders',
                'edit_private_shop_orders',
                'read_private_shop_orders',
                'publish_shop_orders',
                // Courses (ak_set custom post type)
                'edit_post',
                'read_post',
                'delete_post',
                'edit_posts',
                'read_private_posts',
                'edit_others_posts',
                'edit_published_posts',
                'edit_private_posts',
                'publish_posts',
                'delete_posts',
                'delete_published_posts',
                'delete_others_posts',
                'delete_private_posts',
                'upload_files',
                // Products
                'edit_product',
                'read_product',
                'delete_product',
                'edit_products',
                'edit_others_products',
                'publish_products',
                'read_private_products',
                'delete_products',
                'delete_private_products',
                'delete_published_products',
                'delete_others_products',
                'edit_private_products',
                'edit_published_products',
                'manage_product_terms',
                'edit_product_terms',
                'delete_product_terms',
                'assign_product_terms',
                // Coupons
                'edit_shop_coupon',
                'read_shop_coupon',
                'delete_shop_coupon',
                'edit_shop_coupons',
                'edit_others_shop_coupons',
                'publish_shop_coupons',
                'read_private_shop_coupons',
                'delete_shop_coupons',
                'delete_private_shop_coupons',
                'delete_published_shop_coupons',
                'delete_others_shop_coupons',
                'edit_private_shop_coupons',
                'edit_published_shop_coupons',
            ];
            foreach ($granted_caps as $cap) {
                $allcaps[$cap] = true;
            }
        }
        return $allcaps;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function is_roster_manager(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $user = wp_get_current_user();
        if (!$user || !isset($user->roles)) {
            return false;
        }
        // Do not restrict administrators even if they have the role assigned
        if (in_array('administrator', (array) $user->roles, true)) {
            return false;
        }
        return in_array('ak_roster_manager', (array) $user->roles, true);
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Redirect the manager away from unauthorized admin pages.
     * Allows roster, orders (read-only), courses (ak_set), products, coupons, and media.
     * Fires early (priority 1) so it runs before other admin_init callbacks.
     */
    public function enforce_roster_only_access(): void {
        if (!is_admin()) {
            return;
        }
        // Skip AJAX, cron, and REST requests — only restrict browser page loads
        if ((defined('DOING_AJAX')  && DOING_AJAX)
         || (defined('DOING_CRON')  && DOING_CRON)
         || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (!$this->is_roster_manager()) {
            return;
        }

        global $pagenow;

        $allowed_post_types = ['ak_set', 'product', 'shop_coupon', 'shop_order'];

        // Allow: admin.php?page=ak-roster or wc-orders or coupons-moved
        if ($pagenow === 'admin.php') {
            $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
            if (in_array($page, [Roster_Admin_Page::MENU_SLUG, 'wc-orders', 'wc-orders--new', 'coupons-moved'], true)) {
                return;
            }
        }

        // Allow: list screens for allowed post types
        if ($pagenow === 'edit.php') {
            $post_type = isset($_REQUEST['post_type']) ? sanitize_key($_REQUEST['post_type']) : 'post';
            if (in_array($post_type, $allowed_post_types, true)) {
                return;
            }
        }

        // Allow: create new post for courses, products, and coupons
        if ($pagenow === 'post-new.php') {
            $post_type = isset($_REQUEST['post_type']) ? sanitize_key($_REQUEST['post_type']) : 'post';
            if (in_array($post_type, ['ak_set', 'product', 'shop_coupon'], true)) {
                return;
            }
        }

        // Allow: single post edit, trash, untrash, delete, and save
        if ($pagenow === 'post.php') {
            $post_id = isset($_REQUEST['post']) ? (int) $_REQUEST['post'] : (isset($_REQUEST['post_ID']) ? (int) $_REQUEST['post_ID'] : 0);
            if ($post_id > 0 && function_exists('get_post_type')) {
                $post_type = get_post_type($post_id);
                if (in_array($post_type, $allowed_post_types, true)) {
                    return;
                }
            }
            $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';
            if (in_array($action, ['edit', 'trash', 'untrash', 'delete', 'editpost'], true)) {
                return;
            }
        }

        // Allow: taxonomies for products and courses (e.g. product_cat, product_tag)
        if (in_array($pagenow, ['edit-tags.php', 'term.php'], true)) {
            $taxonomy  = isset($_REQUEST['taxonomy']) ? sanitize_key($_REQUEST['taxonomy']) : '';
            $post_type = isset($_REQUEST['post_type']) ? sanitize_key($_REQUEST['post_type']) : '';
            if (in_array($post_type, ['product', 'ak_set'], true)) {
                return;
            }
            if ($taxonomy && function_exists('get_taxonomy')) {
                $tax_obj = get_taxonomy($taxonomy);
                if ($tax_obj && !empty(array_intersect((array) $tax_obj->object_type, ['product', 'ak_set']))) {
                    return;
                }
            }
        }

        // Allow: media management & uploads
        if (in_array($pagenow, ['upload.php', 'media-new.php', 'async-upload.php'], true)) {
            return;
        }

        // Everything else → redirect to the roster page
        wp_safe_redirect(admin_url('admin.php?page=' . Roster_Admin_Page::MENU_SLUG));
        exit;
    }

    /**
     * Block POST requests that attempt to mutate or save orders.
     */
    public function prevent_order_mutations(): void {
        if (!$this->is_roster_manager()) {
            return;
        }

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $page      = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
            $post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : '';
            $action    = isset($_POST['action']) ? sanitize_key($_POST['action']) : '';

            $is_order_post = ($post_type === 'shop_order');
            if (!$is_order_post && isset($_POST['post_ID']) && function_exists('get_post_type')) {
                $post_id = (int) $_POST['post_ID'];
                if ($post_id > 0 && get_post_type($post_id) === 'shop_order') {
                    $is_order_post = true;
                }
            }

            if ($page === 'wc-orders' || $is_order_post || ($action && str_contains($action, 'order') && !in_array($post_type, ['product', 'ak_set', 'shop_coupon'], true))) {
                wp_die(
                    esc_html__('Menadżer rejestru posiada uprawnienia tylko do podglądu zamówień. Modyfikacja danych jest zablokowana.', 'ak-product-set'),
                    esc_html__('Brak uprawnień', 'ak-product-set'),
                    ['back_link' => true]
                );
            }
        }
    }

    /**
     * Prevent programmatic WooCommerce order object saves for roster managers.
     */
    public function block_order_save($order): void {
        if ($this->is_roster_manager()) {
            wp_die(
                esc_html__('Menadżer rejestru posiada uprawnienia tylko do podglądu zamówień. Modyfikacja danych jest zablokowana.', 'ak-product-set')
            );
        }
    }

    /**
     * Remove bulk actions on order list tables for roster managers.
     */
    public function remove_order_bulk_actions(array $actions): array {
        if ($this->is_roster_manager()) {
            return [];
        }
        return $actions;
    }

    /**
     * Display a notice explaining read-only access when viewing WooCommerce orders.
     */
    public function show_read_only_notice(): void {
        if (!$this->is_roster_manager()) {
            return;
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && (in_array($screen->id, ['woocommerce_page_wc-orders', 'shop_order'], true) || str_contains($screen->id, 'order'))) {
                echo '<div class="notice notice-info"><p><strong>' . esc_html__('Tryb tylko do odczytu:', 'ak-product-set') . '</strong> ' . esc_html__('Masz dostęp do podglądu zamówień. Modyfikacja danych i zapisywanie zmian są wyłączone.', 'ak-product-set') . '</p></div>';
            }
        }
    }

    /**
     * Inject CSS and JS to hide save buttons, edit links, and disable inputs on order screens.
     */
    public function inject_read_only_order_styles(): void {
        if (!$this->is_roster_manager()) {
            return;
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if (!$screen || (!in_array($screen->id, ['woocommerce_page_wc-orders', 'shop_order'], true) && !str_contains($screen->id, 'order'))) {
                return;
            }
        }

        ?>
        <style>
        /* Hide edit & save controls for read-only roster manager */
        #publishing-action,
        .order-actions-save,
        button.save-order,
        button.save_order,
        .wc-order-actions-save,
        #woocommerce-order-actions input[type="submit"],
        .wc-order-data-row-toggle,
        .edit_address,
        .add-line-items,
        .refund-items,
        .delete_note,
        .add_note,
        .wc-order-bulk-actions,
        .column-wc_actions .button,
        #actions button,
        .bulk-actions-select,
        #doaction,
        #doaction2 {
            display: none !important;
        }

        .order_data_column input,
        .order_data_column select,
        .order_data_column textarea,
        #woocommerce-order-items input,
        #woocommerce-order-items select,
        #woocommerce-order-items textarea {
            pointer-events: none !important;
            background-color: #f7f7f7 !important;
            border-color: #ddd !important;
            opacity: 0.85 !important;
        }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.order_data_column input, .order_data_column select, .order_data_column textarea, #woocommerce-order-items input, #woocommerce-order-items select, #woocommerce-order-items textarea').forEach(function(el) {
                el.setAttribute('disabled', 'disabled');
                el.setAttribute('readonly', 'readonly');
            });
        });
        </script>
        <?php
    }

    /**
     * Remove admin menu items outside allowed modules.
     * Allowed: Roster, Courses (ak_set), Products, Coupons, WooCommerce (Orders only), and Media.
     * Runs at priority 999 so all items have been registered first.
     */
    public function strip_menu_for_manager(): void {
        if (!$this->is_roster_manager()) {
            return;
        }

        global $menu, $submenu;

        $allowed_slugs = [
            Roster_Admin_Page::MENU_SLUG,
            'edit.php?post_type=ak_set',
            'edit.php?post_type=product',
            'edit.php?post_type=shop_coupon',
            'woocommerce',
            'wc-orders',
            'edit.php?post_type=shop_order',
            'upload.php',
        ];

        foreach ($menu as $item) {
            if (isset($item[2]) && !in_array($item[2], $allowed_slugs, true)) {
                remove_menu_page($item[2]);
            }
        }

        // Restrict WooCommerce submenu to Orders and Coupons only
        if (isset($submenu['woocommerce'])) {
            $allowed_wc_submenus = [
                'wc-orders',
                'edit.php?post_type=shop_order',
                'edit.php?post_type=shop_coupon',
                'coupons-moved',
            ];
            foreach ($submenu['woocommerce'] as $key => $sub_item) {
                if (isset($sub_item[2]) && !in_array($sub_item[2], $allowed_wc_submenus, true)) {
                    unset($submenu['woocommerce'][$key]);
                }
            }
        }
    }

    /**
     * Remove admin-bar nodes that expose navigation to other parts of WP.
     */
    public function strip_admin_bar_for_manager(): void {
        if (!$this->is_roster_manager()) {
            return;
        }

        global $wp_admin_bar;
        if (!$wp_admin_bar) {
            return;
        }

        $remove_nodes = [
            'wp-logo',
            'about',
            'wporg',
            'documentation',
            'support-forums',
            'feedback',
            'updates',
            'comments',
            'new-content',
            'customize',
            'edit',
        ];

        foreach ($remove_nodes as $node) {
            $wp_admin_bar->remove_node($node);
        }
    }
}

