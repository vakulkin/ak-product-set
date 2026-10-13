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

    public function test_get_participants_by_weekend_excludes_cancelled_orders(): void {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public function prepare($query, ...$args) {
                return $query;
            }
            public function get_results($query) {
                // Return items for weekend
                if (str_contains($query, 'woocommerce_order_items oi')) {
                    return [
                        (object) ['order_item_id' => 1, 'order_id' => 201],
                        (object) ['order_item_id' => 2, 'order_id' => 202],
                        (object) ['order_item_id' => 3, 'order_id' => 203],
                    ];
                }
                // Return item meta
                if (str_contains($query, 'woocommerce_order_itemmeta')) {
                    return [
                        (object) [
                            'order_item_id' => 1,
                            'meta_key'      => '_ak_participants',
                            'meta_value'    => serialize([['id' => 'uuid-1', 'name' => 'Jan Kowalski', 'email' => 'jan@example.com']]),
                        ],
                        (object) [
                            'order_item_id' => 2,
                            'meta_key'      => '_ak_participants',
                            'meta_value'    => serialize([['id' => 'uuid-2', 'name' => 'Anna Nowak', 'email' => 'anna@example.com']]),
                        ],
                        (object) [
                            'order_item_id' => 3,
                            'meta_key'      => '_ak_participants',
                            'meta_value'    => serialize([['id' => 'uuid-3', 'name' => 'Piotr Zieliński', 'email' => 'piotr@example.com']]),
                        ],
                    ];
                }
                return [];
            }
        };

        // Mock wc_get_order
        Functions\when('wc_get_order')->alias(function($order_id) {
            $statuses = [
                201 => 'completed',
                202 => 'cancelled',
                203 => 'wc-cancelled',
            ];
            if (!isset($statuses[$order_id])) {
                return null;
            }
            return new class($statuses[$order_id]) {
                private string $status;
                public function __construct(string $status) { $this->status = $status; }
                public function get_status(): string { return $this->status; }
                public function get_date_paid() { return null; }
                public function get_payment_method_title(): string { return 'Przelewy24'; }
            };
        });

        $participants = Roster_Query::get_participants_by_weekend(101);

        // Only Order 201 (completed) should be returned; Order 202 and 203 (cancelled) must be excluded
        $this->assertCount(1, $participants);
        $this->assertEquals('Jan Kowalski', $participants[0]['name']);
        $this->assertEquals(201, $participants[0]['order_id']);
        $this->assertEquals('settled', $participants[0]['balance_status']);
        $this->assertEquals(0.0, $participants[0]['balance_amount']);
    }

    public function test_get_participants_by_weekend_loads_explicit_balance_meta(): void {
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public function prepare($query, ...$args) { return $query; }
            public function get_results($query) {
                if (str_contains($query, 'woocommerce_order_items oi')) {
                    return [(object) ['order_item_id' => 10, 'order_id' => 301]];
                }
                if (str_contains($query, 'woocommerce_order_itemmeta')) {
                    return [
                        (object) [
                            'order_item_id' => 10,
                            'meta_key'      => '_ak_participants',
                            'meta_value'    => serialize([['id' => 'uuid-10', 'name' => 'Marek Nowak', 'email' => 'marek@example.com']]),
                        ],
                    ];
                }
                return [];
            }
        };

        Functions\when('wc_get_order')->alias(function($order_id) {
            return new class {
                public function get_status(): string { return 'processing'; }
                public function get_date_paid() { return null; }
                public function get_payment_method_title(): string { return 'BLIK'; }
                public function get_meta(string $key) {
                    if ($key === '_ak_balance_status') return 'underpaid';
                    if ($key === '_ak_balance_amount') return 450.50;
                    return null;
                }
            };
        });

        $participants = Roster_Query::get_participants_by_weekend(101);

        $this->assertCount(1, $participants);
        $this->assertEquals('underpaid', $participants[0]['balance_status']);
        $this->assertEquals(450.50, $participants[0]['balance_amount']);
    }
}
