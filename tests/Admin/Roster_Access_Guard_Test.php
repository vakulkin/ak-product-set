<?php

namespace AK_Set\Tests\Admin;

use AK_Set\Tests\TestCase;
use AK_Set\Admin\Roster_Access_Guard;
use Brain\Monkey\Functions;

class Roster_Access_Guard_Test extends TestCase {

    public function test_remove_order_bulk_actions_returns_empty_array_for_roster_manager(): void {
        Functions\stubs([
            'is_user_logged_in' => true,
            'wp_get_current_user' => (object) ['roles' => ['ak_roster_manager']],
        ]);

        $guard = new Roster_Access_Guard();
        $actions = ['mark_processing' => 'Change status to processing'];

        $result = $guard->remove_order_bulk_actions($actions);

        $this->assertEmpty($result);
    }

    public function test_allow_admin_access_returns_false_for_roster_cap(): void {
        Functions\stubs([
            'current_user_can' => true,
        ]);

        $guard = new Roster_Access_Guard();
        $this->assertFalse($guard->allow_admin_access(true));
    }

    public function test_force_show_admin_bar_returns_true_for_roster_manager(): void {
        Functions\stubs([
            'is_user_logged_in' => true,
            'wp_get_current_user' => (object) ['roles' => ['ak_roster_manager']],
        ]);

        $guard = new Roster_Access_Guard();
        $this->assertTrue($guard->force_show_admin_bar_for_manager(false));
    }

    public function test_filter_user_caps_grants_product_coupon_and_course_caps(): void {
        $guard = new Roster_Access_Guard();
        $user = new \WP_User();
        $user->roles = ['ak_roster_manager'];

        $allcaps = $guard->filter_user_caps(['read' => true], [], [], $user);

        // Course capabilities (ak_set)
        $this->assertTrue($allcaps['edit_posts'] ?? false);
        $this->assertTrue($allcaps['publish_posts'] ?? false);
        $this->assertTrue($allcaps['delete_posts'] ?? false);
        $this->assertTrue($allcaps['upload_files'] ?? false);

        // Product capabilities
        $this->assertTrue($allcaps['edit_products'] ?? false);
        $this->assertTrue($allcaps['publish_products'] ?? false);
        $this->assertTrue($allcaps['delete_products'] ?? false);
        $this->assertTrue($allcaps['manage_product_terms'] ?? false);

        // Coupon capabilities
        $this->assertTrue($allcaps['edit_shop_coupons'] ?? false);
        $this->assertTrue($allcaps['publish_shop_coupons'] ?? false);
        $this->assertTrue($allcaps['delete_shop_coupons'] ?? false);
    }

    public function test_filter_map_meta_cap_maps_order_and_preserves_other_posts(): void {
        Functions\stubs([
            'get_userdata' => (object) ['roles' => ['ak_roster_manager']],
        ]);

        $guard = new Roster_Access_Guard();

        // Direct shop_order meta caps map to ak_view_roster
        $shop_order_cap = $guard->filter_map_meta_cap(['edit_shop_order'], 'edit_shop_order', 1, [10]);
        $this->assertEquals(['ak_view_roster'], $shop_order_cap);

        // Non-orders (get_post_type returns 'product' in bootstrap) preserve original caps
        $product_meta = $guard->filter_map_meta_cap(['edit_post'], 'edit_post', 1, [20]);
        $this->assertEquals(['edit_post'], $product_meta);
    }

    public function test_strip_menu_for_manager_preserves_allowed_slugs(): void {
        Functions\stubs([
            'is_user_logged_in'   => true,
            'wp_get_current_user' => (object) ['roles' => ['ak_roster_manager']],
        ]);

        $removed = [];
        Functions\when('remove_menu_page')->alias(function ($slug) use (&$removed) {
            $removed[] = $slug;
        });

        global $menu, $submenu;
        $menu = [
            [0 => 'Dashboard', 2 => 'index.php'],
            [0 => 'Rejestr',   2 => 'ak-roster'],
            [0 => 'Kursy',     2 => 'edit.php?post_type=ak_set'],
            [0 => 'Produkty',  2 => 'edit.php?post_type=product'],
            [0 => 'Kupony',    2 => 'edit.php?post_type=shop_coupon'],
            [0 => 'Wpisy',     2 => 'edit.php'],
            [0 => 'Wtyczki',   2 => 'plugins.php'],
        ];
        $submenu = [
            'woocommerce' => [
                0 => ['Zamówienia', 'read', 'wc-orders'],
                1 => ['Ustawienia', 'read', 'wc-settings'],
                2 => ['Raporty',    'read', 'wc-reports'],
            ],
        ];

        $guard = new Roster_Access_Guard();
        $guard->strip_menu_for_manager();

        // Disallowed menu pages should be removed
        $this->assertContains('index.php', $removed);
        $this->assertContains('edit.php', $removed);
        $this->assertContains('plugins.php', $removed);

        // Allowed modules must not be removed
        $this->assertNotContains('ak-roster', $removed);
        $this->assertNotContains('edit.php?post_type=ak_set', $removed);
        $this->assertNotContains('edit.php?post_type=product', $removed);
        $this->assertNotContains('edit.php?post_type=shop_coupon', $removed);

        // Submenus under WooCommerce: settings and reports removed, orders kept
        $this->assertArrayHasKey(0, $submenu['woocommerce']);
        $this->assertArrayNotHasKey(1, $submenu['woocommerce']);
        $this->assertArrayNotHasKey(2, $submenu['woocommerce']);
    }
}
