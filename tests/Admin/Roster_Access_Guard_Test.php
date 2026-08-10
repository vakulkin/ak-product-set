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
}
