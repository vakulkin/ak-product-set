<?php

namespace AK_Set\Tests\Admin;

use AK_Set\Admin\Roster_Query;
use AK_Set\Tests\TestCase;
use Brain\Monkey\Functions;

class Roster_Query_Test extends TestCase {

    protected function tearDown(): void {
        Roster_Query::reset_cache();
        parent::tearDown();
    }

    public function test_get_weekends_for_selector_fetches_from_sets_and_orders(): void {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public function get_col($query) {
                return [101]; // Order has weekend 101
            }
        };

        // get_posts returns set with ID 10
        Functions\when('get_posts')->justReturn([10]);
        // Set 10 has product 102
        Functions\when('get_field')->alias(function($key, $id) {
            if ($key === 'set_products' && $id === 10) {
                return [102];
            }
            return [];
        });

        // Mock wc_get_product for 101 and 102
        Functions\when('wc_get_product')->alias(function($id) {
            $mockProduct = new class($id) {
                private $id;
                public function __construct($id) { $this->id = $id; }
                public function get_name() { return 'Weekend #' . $this->id; }
            };
            return $mockProduct;
        });

        $weekends = Roster_Query::get_weekends_for_selector();

        $this->assertCount(2, $weekends);
        $this->assertArrayHasKey(101, $weekends);
        $this->assertArrayHasKey(102, $weekends);
        $this->assertEquals('Weekend #101', $weekends[101]);
        $this->assertEquals('Weekend #102', $weekends[102]);
    }
}
