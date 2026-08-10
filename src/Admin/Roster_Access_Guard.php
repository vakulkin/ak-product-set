<?php

namespace AK_Set\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Restricts users with the ak_roster_manager role to the roster page only.
 * Redirects all other admin pages, strips the admin menu and admin bar nodes.
 */
class Roster_Access_Guard {

    public function init(): void {
        add_action('admin_init',                    [$this, 'enforce_roster_only_access'], 1);
        add_action('admin_menu',                    [$this, 'strip_menu_for_manager'], 999);
        add_action('wp_before_admin_bar_render',    [$this, 'strip_admin_bar_for_manager']);
        add_filter('woocommerce_prevent_admin_access', [$this, 'allow_admin_access']);
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function is_roster_manager(): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        $user = wp_get_current_user();
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
        if ($pagenow === 'post.php' && isset($_GET['post'])) {
            $post_id = (int) $_GET['post'];
            if ($post_id > 0 && get_post_type($post_id) === 'shop_order') {
                return;
            }
        }

        // Everything else → redirect to the roster page
        wp_safe_redirect(admin_url('admin.php?page=' . Roster_Admin_Page::MENU_SLUG));
        exit;
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
            'site-name',
            'view-site',
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
