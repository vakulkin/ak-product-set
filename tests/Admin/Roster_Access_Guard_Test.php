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

    public function test_remove_order_bulk_actions_returns_empty_array_for_roster_reader(): void {
        Functions\stubs([
            'is_user_logged_in'   => true,
            'wp_get_current_user' => (object) ['roles' => ['ak_roster_reader']],
        ]);

        $guard   = new Roster_Access_Guard();
        $actions = ['mark_processing' => 'Change status to processing'];

        $result = $guard->remove_order_bulk_actions($actions);

        $this->assertEmpty($result);
    }

    public function test_product_bulk_actions_for_reader_and_manager(): void {
        $guard   = new Roster_Access_Guard();
        $actions = ['trash' => 'Move to Trash'];

        // Reader should have product bulk actions stripped
        Functions\stubs([
            'is_user_logged_in'   => true,
            'wp_get_current_user' => (object) ['roles' => ['ak_roster_reader']],
        ]);
        $this->assertEmpty($guard->remove_product_bulk_actions($actions));

        // Manager should keep product bulk actions
        Functions\stubs([
            'is_user_logged_in'   => true,
            'wp_get_current_user' => (object) ['roles' => ['ak_roster_manager']],
        ]);
        $this->assertEquals($actions, $guard->remove_product_bulk_actions($actions));
    }

    public function test_filter_product_row_actions_removes_trash_quick_edit_duplicate_for_reader(): void {
        Functions\stubs([
            'is_user_logged_in'   => true,
            'wp_get_current_user' => (object) ['roles' => ['ak_roster_reader']],
        ]);

        $guard = new Roster_Access_Guard();
        $post  = (object) ['post_type' => 'product'];
        $actions = [
            'edit'               => '<a href="#">Edit</a>',
            'inline hide-if-no-js' => '<button>Quick Edit</button>',
            'trash'              => '<a href="#">Trash</a>',
            'duplicate'          => '<a href="#">Duplicate</a>',
            'view'               => '<a href="#">View</a>',
        ];

        $filtered = $guard->filter_product_row_actions($actions, $post);

        $this->assertArrayHasKey('edit', $filtered);
        $this->assertArrayHasKey('view', $filtered);
        $this->assertArrayNotHasKey('inline hide-if-no-js', $filtered);
        $this->assertArrayNotHasKey('trash', $filtered);
        $this->assertArrayNotHasKey('duplicate', $filtered);
    }

    public function test_force_show_admin_bar_returns_true_for_roster_reader(): void {
        Functions\stubs([
            'is_user_logged_in'   => true,
            'wp_get_current_user' => (object) ['roles' => ['ak_roster_reader']],
        ]);

        $guard = new Roster_Access_Guard();
        $this->assertTrue($guard->force_show_admin_bar_for_manager(false));
    }

    public function test_filter_user_caps_grants_read_only_and_denies_mutations_for_reader(): void {
        $guard = new Roster_Access_Guard();
        $user  = new \WP_User();
        $user->roles = ['ak_roster_reader'];

        $allcaps = $guard->filter_user_caps(['read' => true], [], [], $user);

        // Allowed: Roster view & download
        $this->assertTrue($allcaps['ak_view_roster'] ?? false);

        // Allowed: Orders (read-only)
        $this->assertTrue($allcaps['edit_shop_orders'] ?? false);
        $this->assertTrue($allcaps['read_shop_order'] ?? false);

        // Allowed: Products (view only)
        $this->assertTrue($allcaps['edit_products'] ?? false);
        $this->assertTrue($allcaps['read_product'] ?? false);
        $this->assertTrue($allcaps['edit_product'] ?? false);
        $this->assertTrue($allcaps['edit_others_products'] ?? false);
        $this->assertTrue($allcaps['edit_published_products'] ?? false);

        // Denied: Product mutations and deletions
        $this->assertFalse($allcaps['publish_products'] ?? false);
        $this->assertFalse($allcaps['delete_products'] ?? false);
        $this->assertFalse($allcaps['delete_product'] ?? false);
        $this->assertFalse($allcaps['manage_product_terms'] ?? false);

        // Denied: Courses (ak_set)
        $this->assertFalse($allcaps['edit_posts'] ?? false);
        $this->assertFalse($allcaps['publish_posts'] ?? false);
        $this->assertFalse($allcaps['delete_posts'] ?? false);

        // Denied: Coupons
        $this->assertFalse($allcaps['edit_shop_coupons'] ?? false);
        $this->assertFalse($allcaps['delete_shop_coupons'] ?? false);

        // Denied: Media uploads
        $this->assertFalse($allcaps['upload_files'] ?? false);
    }

    public function test_filter_map_meta_cap_maps_orders_for_roster_reader(): void {
        Functions\stubs([
            'get_userdata' => (object) ['roles' => ['ak_roster_reader']],
        ]);

        $guard = new Roster_Access_Guard();

        // Direct shop_order meta caps map to ak_view_roster
        $shop_order_cap = $guard->filter_map_meta_cap(['edit_shop_order'], 'edit_shop_order', 1, [10]);
        $this->assertEquals(['ak_view_roster'], $shop_order_cap);

        // Non-orders preserve original caps
        $product_meta = $guard->filter_map_meta_cap(['edit_post'], 'edit_post', 1, [20]);
        $this->assertEquals(['edit_post'], $product_meta);
    }

    public function test_strip_menu_for_reader_removes_courses_coupons_and_media(): void {
        Functions\stubs([
            'is_user_logged_in'   => true,
            'wp_get_current_user' => (object) ['roles' => ['ak_roster_reader']],
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
            [0 => 'Media',     2 => 'upload.php'],
        ];
        $submenu = [
            'woocommerce' => [
                0 => ['Zamówienia', 'read', 'wc-orders'],
                1 => ['Kupony',     'read', 'edit.php?post_type=shop_coupon'],
                2 => ['Ustawienia', 'read', 'wc-settings'],
            ],
            'edit.php?post_type=product' => [
                0 => ['Wszystkie produkty', 'read', 'edit.php?post_type=product'],
                1 => ['Dodaj nowy',         'read', 'post-new.php?post_type=product'],
                2 => ['Kategorie',          'read', 'edit-tags.php?taxonomy=product_cat&post_type=product'],
            ],
        ];

        $guard = new Roster_Access_Guard();
        $guard->strip_menu_for_manager();

        // For reader: Courses, Coupons, and Media must be removed
        $this->assertContains('edit.php?post_type=ak_set', $removed);
        $this->assertContains('edit.php?post_type=shop_coupon', $removed);
        $this->assertContains('upload.php', $removed);
        $this->assertContains('index.php', $removed);

        // Allowed modules must not be removed
        $this->assertNotContains('ak-roster', $removed);
        $this->assertNotContains('edit.php?post_type=product', $removed);

        // In WooCommerce submenu: orders kept, coupons and settings removed
        $this->assertArrayHasKey(0, $submenu['woocommerce']);
        $this->assertArrayNotHasKey(1, $submenu['woocommerce']);
        $this->assertArrayNotHasKey(2, $submenu['woocommerce']);

        // In Product submenu: only 'All Products' kept, 'Add New' and 'Categories' removed
        $this->assertArrayHasKey(0, $submenu['edit.php?post_type=product']);
        $this->assertArrayNotHasKey(1, $submenu['edit.php?post_type=product']);
        $this->assertArrayNotHasKey(2, $submenu['edit.php?post_type=product']);
    }
}
