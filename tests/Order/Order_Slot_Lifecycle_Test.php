<?php

namespace AK_Set\Tests\Order;

use AK_Set\Tests\TestCase;
use AK_Set\Order\Order_Recalculator;
use AK_Set\Order\Order_Participant_Slot_Manager;
use AK_Set\Order\Order_Participant_Profile_Service;
use Mockery;
use Brain\Monkey\Functions;

class Order_Slot_Lifecycle_Test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Functions\stubs([
            'get_field' => function() {
                return null;
            },
            'get_post' => function($id) {
                $post = new \stdClass();
                $post->ID = $id;
                $post->post_title = 'Zestaw ' . $id;
                return $post;
            },
            'get_post_meta' => function($id, $key = '', $single = false) {
                return $single ? '100.00' : ['100.00'];
            },
            'wc_get_product' => function($id) {
                $p = Mockery::mock('WC_Product');
                $p->shouldReceive('get_name')->andReturn('Weekend ' . $id);
                return $p;
            },
        ]);
    }

    public function test_soft_cancellation_marks_participant_as_cancelled_and_increments_retained_tshirt(): void {
        $item_meta = [
            '_ak_participants' => [
                ['id' => 'uuid-1', 'name' => 'Jan Kowalski', 'status' => 'active'],
            ],
            '_ak_parent_set_id' => 10,
        ];

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldIgnoreMissing();
        $item->shouldReceive('get_id')->andReturn(101);
        $item->shouldReceive('get_product_id')->andReturn(501);
        $item->shouldReceive('get_name')->andReturn('Weekend 501');
        $item->shouldReceive('get_meta')->with('_ak_participants')->andReturnUsing(function() use (&$item_meta) {
            return $item_meta['_ak_participants'];
        });
        $item->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturnUsing(function() use (&$item_meta) {
            return $item_meta['_ak_parent_set_id'];
        });
        $item->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$item_meta) {
            $item_meta[$k] = $v;
        });

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(999);
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item]);
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn(1000.0);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn(1000.0);
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_total')->andReturn(1000.0);
        $order->shouldReceive('get_date_created')->andReturn(null);

        // Cancel with retained T-shirt (true)
        $result = Order_Participant_Slot_Manager::remove_weekend_for_participant($order, 'uuid-1', 501, true, false);

        $this->assertTrue($result['success']);
        $this->assertEquals('Jan Kowalski', $result['participant_name']);

        // Assert slot is soft-cancelled and counter incremented
        $this->assertCount(1, $item_meta['_ak_participants']);
        $slot = $item_meta['_ak_participants'][0];
        $this->assertEquals('cancelled', $slot['status']);
        $this->assertNotEmpty($slot['cancelled_at']);
        $this->assertEquals(1, $slot['tshirt_retained_count']);

        // Cancel again with retained T-shirt (true) - simulates 2nd cancellation or cost accumulation
        $result2 = Order_Participant_Slot_Manager::remove_weekend_for_participant($order, 'uuid-1', 501, true, false);
        $this->assertTrue($result2['success']);
        $this->assertEquals(2, $item_meta['_ak_participants'][0]['tshirt_retained_count']);
    }

    public function test_recalculation_ignores_cancelled_and_transferred_slots_in_headcount(): void {
        $item1 = Mockery::mock('WC_Order_Item_Product');
        $item1->shouldIgnoreMissing();
        $item1->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-1', 'name' => 'Active Jan', 'status' => 'active'],
            ['id' => 'uuid-2', 'name' => 'Cancelled Anna', 'status' => 'cancelled'],
            ['id' => 'uuid-3', 'name' => 'Transferred Piotr', 'status' => 'transferred'],
        ]);

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item1]);

        $active_headcount = Order_Recalculator::detect_total_headcount($order);
        $this->assertEquals(1, $active_headcount);
    }

    public function test_restore_weekend_for_participant_restores_status_to_active(): void {
        $item_meta = [
            '_ak_participants' => [
                [
                    'id'                    => 'uuid-1',
                    'name'                  => 'Jan Kowalski',
                    'status'                => 'cancelled',
                    'cancelled_at'          => '2026-10-14 10:00:00',
                    'tshirt_retained_count' => 1,
                ],
            ],
            '_ak_parent_set_id' => 10,
        ];

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldIgnoreMissing();
        $item->shouldReceive('get_id')->andReturn(101);
        $item->shouldReceive('get_product_id')->andReturn(501);
        $item->shouldReceive('get_name')->andReturn('Weekend 501');
        $item->shouldReceive('get_meta')->with('_ak_participants')->andReturnUsing(function() use (&$item_meta) {
            return $item_meta['_ak_participants'];
        });
        $item->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturnUsing(function() use (&$item_meta) {
            return $item_meta['_ak_parent_set_id'];
        });
        $item->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$item_meta) {
            $item_meta[$k] = $v;
        });

        $order_status = 'cancelled';
        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(888);
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item]);
        $order->shouldReceive('get_status')->andReturnUsing(function() use (&$order_status) {
            return $order_status;
        });
        $order->shouldReceive('update_status')->andReturnUsing(function($st) use (&$order_status) {
            $order_status = $st;
        });
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn(1000.0);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn(1000.0);
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_total')->andReturn(0.0);
        $order->shouldReceive('get_date_created')->andReturn(null);

        $result = Order_Participant_Slot_Manager::restore_weekend_for_participant($order, 'uuid-1', 501);

        $this->assertTrue($result['success']);
        $this->assertEquals('Jan Kowalski', $result['participant_name']);

        // Assert slot is back to active, timestamps cleared, but retained t-shirt counter preserved
        $slot = $item_meta['_ak_participants'][0];
        $this->assertEquals('active', $slot['status']);
        $this->assertArrayNotHasKey('cancelled_at', $slot);
        $this->assertEquals(1, $slot['tshirt_retained_count']);
    }

    public function test_profile_update_with_tshirt_produced_charges_fee_and_increments_slot(): void {
        update_option('ak_set_tshirt_cost', 60.0);

        $order_fees = [];

        $item_meta = [
            '_ak_participants' => [
                [
                    'id'                    => 'uuid-1',
                    'name'                  => 'Jan Kowalski',
                    'status'                => 'active',
                    'tshirt_size'           => 'M',
                    'tshirt_cut'            => 'men',
                    'tshirt_retained_count' => 0,
                ],
            ],
        ];

        $line_item = Mockery::mock('WC_Order_Item_Product');
        $line_item->shouldIgnoreMissing();
        $line_item->shouldReceive('get_product_id')->andReturn(501);
        $line_item->shouldReceive('get_meta')->with('_ak_participants')->andReturnUsing(function() use (&$item_meta) {
            return $item_meta['_ak_participants'];
        });
        $line_item->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$item_meta) {
            $item_meta[$k] = $v;
        });

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(666);
        $order->shouldReceive('get_items')->with('fee')->andReturnUsing(function() use (&$order_fees) {
            return $order_fees;
        });
        $order->shouldReceive('add_item')->with(Mockery::on(function($fee) use (&$order_fees) {
            $order_fees[] = $fee;
            return true;
        }));
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$line_item]);
        $order->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn(1000.0);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn(1000.0);
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_total')->andReturn(1000.0);
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('save')->atLeast()->once();

        $result = Order_Participant_Profile_Service::update_participant_data($order, 'uuid-1', [
            'name'            => 'Jan Nowe Nazwisko',
            'tshirt_produced' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('Jan Nowe Nazwisko', $result['participant_name']);
        $this->assertEquals(1, $item_meta['_ak_participants'][0]['tshirt_retained_count']);
        $this->assertCount(1, $order_fees);

        unset($GLOBALS['_wp_options']['ak_set_tshirt_cost']);
    }

    public function test_all_cancelled_participants_order_keeps_line_items_with_zero_and_retains_tshirt_fee(): void {
        update_option('ak_set_tshirt_cost', 60.0);

        $line_item = Mockery::mock('WC_Order_Item_Product');
        $line_item->shouldIgnoreMissing();
        $line_item->shouldReceive('get_id')->andReturn(55);
        $line_item->shouldReceive('get_product_id')->andReturn(101);
        $line_item->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);
        $line_item->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-1', 'name' => 'Jan Kowalski', 'status' => 'cancelled', 'tshirt_retained_count' => 1],
        ]);
        $line_item->shouldReceive('set_quantity')->with(0)->once();
        $line_item->shouldReceive('set_subtotal')->with(0.0)->once();
        $line_item->shouldReceive('set_total')->with(0.0)->once();
        $line_item->shouldReceive('update_meta_data')->with('_ak_headcount', 0)->once();

        $fee_item = new \WC_Order_Item_Fee();
        $fee_item->set_name('Koszt wyprodukowanej koszulki');
        $fee_item->add_meta_data('_ak_is_tshirt_fee', '1', true);
        $fee_item->set_total(60.0);

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(500);
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$line_item]);
        $order->shouldReceive('get_items')->with('fee')->andReturn([$fee_item]);
        $order->shouldReceive('get_items')->with('coupon')->andReturn([]);
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn(800.0);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn(800.0);
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('update_status')->with('cancelled', Mockery::any())->once();
        $order->shouldReceive('set_total')->with(60.0)->once();

        // Ensure remove_item is NEVER called on the line item
        $order->shouldReceive('remove_item')->never();

        $result = Order_Recalculator::recalculate($order, [
            'reason' => 'Anulowano wszystkich uczestników',
        ]);

        $this->assertEquals(60.0, $result['recalculated_total']);
        $this->assertEquals(0, $result['headcount']);
        // Customer paid 800 zł, new total is 60 zł -> overpaid -740 zł balance
        $this->assertEquals(-740.0, $result['balance_amount']);
        $this->assertEquals(Order_Recalculator::BALANCE_OVERPAID, $result['status']);

        unset($GLOBALS['_wp_options']['ak_set_tshirt_cost']);
    }

    public function test_no_double_counting_when_recalculating_multiple_times(): void {
        update_option('ak_set_tshirt_cost', 60.0);

        $line_item = Mockery::mock('WC_Order_Item_Product');
        $line_item->shouldIgnoreMissing();
        $line_item->shouldReceive('get_id')->andReturn(55);
        $line_item->shouldReceive('get_product_id')->andReturn(101);
        $line_item->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);
        $line_item->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-1', 'name' => 'Jan Kowalski', 'status' => 'cancelled', 'tshirt_retained_count' => 1],
        ]);

        $fee_item = new \WC_Order_Item_Fee();
        $fee_item->set_name('Koszt wyprodukowanej koszulki');
        $fee_item->add_meta_data('_ak_is_tshirt_fee', '1', true);
        $fee_item->set_total(60.0);

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(500);
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$line_item]);
        $order->shouldReceive('get_items')->with('fee')->andReturn([$fee_item]);
        $order->shouldReceive('get_items')->with('coupon')->andReturn([]);
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn(800.0);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn(800.0);
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_date_created')->andReturn(null);

        // First recalculation
        $result1 = Order_Recalculator::recalculate($order);
        $this->assertEquals(60.0, $result1['recalculated_total']);
        $this->assertEquals(60.0, $fee_item->get_total());

        // Second recalculation without any new cancellation (must NOT double to 120)
        $result2 = Order_Recalculator::recalculate($order);
        $this->assertEquals(60.0, $result2['recalculated_total']);
        $this->assertEquals(60.0, $fee_item->get_total());

        unset($GLOBALS['_wp_options']['ak_set_tshirt_cost']);
    }

    public function test_participant_profile_update_across_multiple_weekends_charges_only_single_tshirt(): void {
        update_option('ak_set_tshirt_cost', 60.0);

        $item_meta_1 = [
            '_ak_participants' => [
                ['id' => 'uuid-1', 'name' => 'Jan Kowalski', 'status' => 'active', 'tshirt_retained_count' => 0],
            ],
            '_ak_parent_set_id' => 10,
        ];
        $item_meta_2 = [
            '_ak_participants' => [
                ['id' => 'uuid-1', 'name' => 'Jan Kowalski', 'status' => 'active', 'tshirt_retained_count' => 0],
            ],
            '_ak_parent_set_id' => 10,
        ];

        $line_item_1 = Mockery::mock('WC_Order_Item_Product');
        $line_item_1->shouldIgnoreMissing();
        $line_item_1->shouldReceive('get_product_id')->andReturn(101);
        $line_item_1->shouldReceive('get_meta')->with('_ak_participants')->andReturnUsing(function() use (&$item_meta_1) {
            return $item_meta_1['_ak_participants'];
        });
        $line_item_1->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$item_meta_1) {
            $item_meta_1[$k] = $v;
        });

        $line_item_2 = Mockery::mock('WC_Order_Item_Product');
        $line_item_2->shouldIgnoreMissing();
        $line_item_2->shouldReceive('get_product_id')->andReturn(102);
        $line_item_2->shouldReceive('get_meta')->with('_ak_participants')->andReturnUsing(function() use (&$item_meta_2) {
            return $item_meta_2['_ak_participants'];
        });
        $line_item_2->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$item_meta_2) {
            $item_meta_2[$k] = $v;
        });

        $order_fees = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(666);
        $order->shouldReceive('get_items')->with('fee')->andReturnUsing(function() use (&$order_fees) {
            return $order_fees;
        });
        $order->shouldReceive('add_item')->with(Mockery::on(function($fee) use (&$order_fees) {
            $order_fees[] = $fee;
            return true;
        }));
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$line_item_1, $line_item_2]);
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn(1000.0);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn(1000.0);
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_total')->andReturn(1000.0);
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('save')->atLeast()->once();

        $result = Order_Participant_Profile_Service::update_participant_data($order, 'uuid-1', [
            'name'            => 'Jan Po Zmianie',
            'tshirt_produced' => true,
        ]);

        $this->assertTrue($result['success']);
        // Item 1 has incremented counter (1)
        $this->assertEquals(1, $item_meta_1['_ak_participants'][0]['tshirt_retained_count']);
        // Item 2 remained 0 (not duplicated across weekends)
        $this->assertEquals(0, $item_meta_2['_ak_participants'][0]['tshirt_retained_count']);
        // Exactly 1 fee item added
        $this->assertCount(1, $order_fees);
        $this->assertEquals(60.0, $order_fees[0]->get_total());

        unset($GLOBALS['_wp_options']['ak_set_tshirt_cost']);
    }

    public function test_produced_tshirts_metadata_saves_json_with_snapshot_cost_and_person_name(): void {
        update_option('ak_set_tshirt_cost', 60.0);

        $item_meta = [
            '_ak_parent_set_id' => 99,
            '_ak_participants'  => [
                [
                    'id'          => 'uuid-abc',
                    'name'        => 'Michał Nowak',
                    'status'      => 'active',
                    'tshirt_size' => 'L',
                    'tshirt_cut'  => 'Męska',
                ],
            ],
        ];

        $line_item = Mockery::mock('WC_Order_Item_Product');
        $line_item->shouldIgnoreMissing();
        $line_item->shouldReceive('get_product_id')->andReturn(501);
        $line_item->shouldReceive('get_meta')->andReturnUsing(function($k) use (&$item_meta) {
            return $item_meta[$k] ?? null;
        });
        $line_item->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$item_meta) {
            $item_meta[$k] = $v;
        });

        // Add produced t-shirt
        $entry = Order_Recalculator::add_item_produced_tshirt($line_item, [
            'participant_uuid' => 'uuid-abc',
            'participant_name' => 'Michał Nowak',
            'cost'             => 60.0,
            'size'             => 'L',
            'cut'              => 'Męska',
            'reason'           => 'cancellation',
        ]);

        $this->assertEquals('Michał Nowak', $entry['participant_name']);
        $this->assertEquals(60.0, $entry['cost']);
        $this->assertEquals('uuid-abc', $entry['participant_uuid']);

        // Check JSON in meta
        $this->assertNotEmpty($item_meta['_ak_produced_tshirts']);
        $decoded = json_decode($item_meta['_ak_produced_tshirts'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertEquals('Michał Nowak', $decoded[0]['participant_name']);
        $this->assertEquals(60.0, $decoded[0]['cost']);

        // Change global option in wp_options to 100 zł!
        update_option('ak_set_tshirt_cost', 100.0);

        // Order total must STILL calculate historical 60.0 zł because it is saved in item metadata!
        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$line_item]);

        $fee_total = Order_Recalculator::get_order_produced_tshirts_total($order);
        $this->assertEquals(60.0, $fee_total);

        // Now delete produced t-shirt by UUID
        $removed = Order_Recalculator::delete_item_produced_tshirt_by_uuid($line_item, $entry['uuid']);
        $this->assertNotNull($removed);
        $this->assertEquals('Michał Nowak', $removed['participant_name']);
        $this->assertEquals(60.0, $removed['cost']);

        $fee_total_after = Order_Recalculator::get_order_produced_tshirts_total($order);
        $this->assertEquals(0.0, $fee_total_after);

        unset($GLOBALS['_wp_options']['ak_set_tshirt_cost']);
    }

    public function test_delete_produced_tshirt_by_uuid_deletes_exact_tshirt(): void {
        $meta_store = [];
        $line_item = Mockery::mock('WC_Order_Item_Product');
        $line_item->shouldIgnoreMissing();
        $line_item->shouldReceive('get_id')->andReturn(101);
        $line_item->shouldReceive('get_meta')->with(Order_Recalculator::PRODUCED_TSHIRTS_META_KEY)->andReturnUsing(function() use (&$meta_store) {
            return $meta_store[Order_Recalculator::PRODUCED_TSHIRTS_META_KEY] ?? [];
        });
        $line_item->shouldReceive('get_meta')->with('_ak_participants')->andReturnUsing(function() use (&$meta_store) {
            return $meta_store['_ak_participants'] ?? [];
        });
        $line_item->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta_store) {
            $meta_store[$k] = $v;
        });

        $meta_store['_ak_participants'] = [
            ['id' => 'u1', 'name' => 'User 1', 'status' => 'active', 'tshirt_retained_count' => 0],
        ];

        // Add 2 produced t-shirts with different UUIDs
        $t1 = Order_Recalculator::add_item_produced_tshirt($line_item, [
            'uuid'             => 'tshirt-uuid-1',
            'participant_uuid' => 'u1',
            'participant_name' => 'User 1',
            'cost'             => 60.0,
            'size'             => 'M',
            'cut'              => 'men',
        ]);
        $t2 = Order_Recalculator::add_item_produced_tshirt($line_item, [
            'uuid'             => 'tshirt-uuid-2',
            'participant_uuid' => 'u1',
            'participant_name' => 'User 1',
            'cost'             => 60.0,
            'size'             => 'L',
            'cut'              => 'men',
        ]);

        $this->assertCount(2, Order_Recalculator::get_item_produced_tshirts($line_item));

        // Delete specifically tshirt-uuid-1
        $deleted = Order_Recalculator::delete_item_produced_tshirt_by_uuid($line_item, 'tshirt-uuid-1');
        $this->assertNotNull($deleted);
        $this->assertEquals('tshirt-uuid-1', $deleted['uuid']);

        // Only tshirt-uuid-2 remains
        $remaining = Order_Recalculator::get_item_produced_tshirts($line_item);
        $this->assertCount(1, $remaining);
        $this->assertEquals('tshirt-uuid-2', $remaining[0]['uuid']);
        $this->assertEquals('L', $remaining[0]['size']);
        $this->assertEquals(1, $meta_store['_ak_participants'][0]['tshirt_retained_count']);
    }

    public function test_update_participant_data_rejected_when_order_cancelled(): void {
        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_status')->andReturn('cancelled');

        $result = Order_Participant_Profile_Service::update_participant_data($order, 'u1', ['name' => 'New Name']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('anulowanym zamówieniu', $result['error']);
    }

    public function test_update_participant_data_rejected_when_participant_cancelled(): void {
        $line_item = Mockery::mock('WC_Order_Item_Product');
        $line_item->shouldIgnoreMissing();
        $line_item->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'u-cancelled', 'name' => 'Inactive Guy', 'status' => 'cancelled'],
        ]);

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_status')->andReturn('processing');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$line_item]);

        $result = Order_Participant_Profile_Service::update_participant_data($order, 'u-cancelled', ['name' => 'Attempt Edit']);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('anulowanego uczestnika', $result['error']);
    }

    public function test_delete_produced_tshirt_allowed_when_order_is_cancelled(): void {
        $meta_store = [];
        $line_item = Mockery::mock('WC_Order_Item_Product');
        $line_item->shouldIgnoreMissing();
        $line_item->shouldReceive('get_id')->andReturn(101);
        $line_item->shouldReceive('get_meta')->andReturnUsing(function($k) use (&$meta_store) {
            return $meta_store[$k] ?? null;
        });
        $line_item->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta_store) {
            $meta_store[$k] = $v;
        });

        $meta_store['_ak_participants'] = [
            ['id' => 'u1', 'name' => 'Cancelled Guy', 'status' => 'cancelled', 'tshirt_retained_count' => 0],
        ];

        $t1 = Order_Recalculator::add_item_produced_tshirt($line_item, [
            'uuid'             => 'tshirt-cancelled-1',
            'participant_uuid' => 'u1',
            'participant_name' => 'Cancelled Guy',
            'cost'             => 60.0,
            'size'             => 'XL',
            'cut'              => 'men',
        ]);

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(999);
        $order->shouldReceive('get_status')->andReturn('cancelled');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$line_item]);
        $order->shouldReceive('get_items')->with('fee')->andReturn([]);
        $order->shouldReceive('get_items')->with('coupon')->andReturn([]);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('60.00');
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('600.00');
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_total')->andReturn(60.0);
        $order->shouldReceive('get_date_created')->andReturn(null);

        $result = Order_Participant_Slot_Manager::delete_produced_tshirt($order, 'tshirt-cancelled-1', 101);
        $this->assertTrue($result['success']);
        $this->assertEmpty(Order_Recalculator::get_item_produced_tshirts($line_item));
        $this->assertEquals(0, $meta_store['_ak_participants'][0]['tshirt_retained_count']);
    }

    public function test_restore_transferred_weekend_restores_status_and_cleans_transferred_meta(): void {
        $item_meta = [
            '_ak_participants' => [
                [
                    'id'                   => 'uuid-transferred-1',
                    'name'                 => 'Piotr Transfer',
                    'status'               => 'transferred',
                    'transferred_to_order' => 202,
                    'transferred_at'       => '2026-10-14 11:00:00',
                ],
            ],
            '_ak_parent_set_id' => 10,
        ];

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldIgnoreMissing();
        $item->shouldReceive('get_id')->andReturn(101);
        $item->shouldReceive('get_product_id')->andReturn(501);
        $item->shouldReceive('get_name')->andReturn('Weekend 501');
        $item->shouldReceive('get_meta')->with('_ak_participants')->andReturnUsing(function() use (&$item_meta) {
            return $item_meta['_ak_participants'];
        });
        $item->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturnUsing(function() use (&$item_meta) {
            return $item_meta['_ak_parent_set_id'];
        });
        $item->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$item_meta) {
            $item_meta[$k] = $v;
        });

        $order_status = 'cancelled';
        $order_meta = [
            '_ak_transferred_to_orders' => [202],
        ];

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(889);
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item]);
        $order->shouldReceive('get_status')->andReturnUsing(function() use (&$order_status) {
            return $order_status;
        });
        $order->shouldReceive('update_status')->andReturnUsing(function($st) use (&$order_status) {
            $order_status = $st;
        });
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn(1000.0);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn(1000.0);
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_total')->andReturn(0.0);
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$order_meta) {
            $order_meta[$k] = $v;
        });

        $result = Order_Participant_Slot_Manager::restore_weekend_for_participant($order, 'uuid-transferred-1', 501);
        $this->assertTrue($result['success']);
        $this->assertEquals('Piotr Transfer', $result['participant_name']);

        $slot = $item_meta['_ak_participants'][0];
        $this->assertEquals('active', $slot['status']);
        $this->assertArrayNotHasKey('transferred_to_order', $slot);
        $this->assertArrayNotHasKey('transferred_at', $slot);

        $this->assertEquals([], $order_meta['_ak_transferred_to_orders']);
    }
}

