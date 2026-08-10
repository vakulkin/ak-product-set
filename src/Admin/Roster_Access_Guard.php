<?php

namespace AK_Set\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Restricts users with the ak_roster_manager role to the roster page and read-only order views.
 * Redirects unauthorized admin pages, strips unrelated admin menu nodes, and blocks any POST/save mutations.
 */
class Roster_Access_Guard {

    public function init(): void {
        add_action('admin_init',                         [$this, 'enforce_roster_only_access'], 1);
        add_action('admin_init',                         [$this, 'prevent_order_mutations']);
        add_action('admin_menu',                         [$this, 'strip_menu_for_manager'], 999);
        add_action('wp_before_admin_bar_render',         [$this, 'strip_admin_bar_for_manager']);
        add_filter('woocommerce_prevent_admin_access',   [$this, 'allow_admin_access']);
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
     * Dynamically grant order reading & viewing capabilities to roster manager users.
     */
    public function filter_user_caps(array $allcaps, array $caps, array $args, \WP_User $user): array {
        if (in_array('ak_roster_manager', (array) $user->roles, true)) {
            $order_caps = [
                'edit_shop_order',
                'read_shop_order',
                'edit_shop_orders',
                'read_shop_orders',
                'edit_others_shop_orders',
                'edit_published_shop_orders',
                'edit_private_shop_orders',
                'read_private_shop_orders',
                'publish_shop_orders',
            ];
            foreach ($order_caps as $cap) {
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
     * Redirect the manager away from every admin page except the roster and order pages.
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

        // Allow: admin.php?page=ak-roster or wc-orders
        if ($pagenow === 'admin.php') {
            $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
            if (in_array($page, [Roster_Admin_Page::MENU_SLUG, 'wc-orders', 'wc-orders--new'], true)) {
                return;
            }
        }

        // Allow: legacy CPT order list
        if ($pagenow === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') {
            return;
        }

        // Allow: single order post edit view
        if ($pagenow === 'post.php') {
            $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
            $action  = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
            if ($post_id > 0) {
                $post_type = get_post_type($post_id);
                if (!$post_type || $post_type === 'shop_order') {
                    return;
                }
            }
            if ($action === 'edit') {
                return;
            }
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
            global $pagenow;
            $page      = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
            $post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : '';
            $action    = isset($_POST['action']) ? sanitize_key($_POST['action']) : '';

            if ($page === 'wc-orders' || $post_type === 'shop_order' || str_contains($action, 'order')) {
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
     * Remove every admin menu item except the roster and order pages.
     * Runs at priority 999 so all items have been registered first.
     */
    public function strip_menu_for_manager(): void {
        if (!$this->is_roster_manager()) {
            return;
        }

        global $menu;

        $allowed_slugs = [
            Roster_Admin_Page::MENU_SLUG,
            'woocommerce',
            'wc-orders',
            'edit.php?post_type=shop_order',
        ];

        foreach ($menu as $item) {
            if (isset($item[2]) && !in_array($item[2], $allowed_slugs, true)) {
                remove_menu_page($item[2]);
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

