<?php

namespace AK_Set\Tests\Order;

use AK_Set\Tests\TestCase;
use AK_Set\Order\Participant_Transfer_Service;
use Mockery;
use Brain\Monkey\Functions;

class Participant_Transfer_Service_Test extends TestCase {

    public function test_remove_participant_returns_error_when_uuid_not_found(): void {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-other', 'name' => 'Alice'],
        ]);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item]);

        $result = Participant_Transfer_Service::remove_participant($order, 'uuid-non-existent');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nie został znaleziony', $result['error']);
    }

    public function test_remove_weekend_for_participant_returns_error_when_not_in_weekend(): void {
        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_product_id')->andReturn(101);
        $item->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-other', 'name' => 'Alice'],
        ]);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item]);

        $result = Participant_Transfer_Service::remove_weekend_for_participant($order, 'uuid-1', 101);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nie został znaleziony w wybranym terminie', $result['error']);
    }

    public function test_reschedule_participant_rejects_same_weekend(): void {
        $order = Mockery::mock('WC_Order');
        $result = Participant_Transfer_Service::reschedule_participant($order, 'uuid-1', 101, 101);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('musi różnić się', $result['error']);
    }

    public function test_reschedule_participant_rejects_destination_if_already_enrolled(): void {
        $item1 = Mockery::mock('WC_Order_Item_Product');
        $item1->shouldReceive('get_product_id')->andReturn(101);
        $item1->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-1', 'name' => 'Anton'],
        ]);
        $item1->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);

        $item2 = Mockery::mock('WC_Order_Item_Product');
        $item2->shouldReceive('get_product_id')->andReturn(102);
        $item2->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-1', 'name' => 'Anton'], // Already enrolled in 102!
        ]);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item1, $item2]);

        $result = Participant_Transfer_Service::reschedule_weekend_for_participant($order, 'uuid-1', 101, 102);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('jest już zapisany na wybrany termin docelowy', $result['error']);
    }

    public function test_transfer_to_existing_order_rejects_same_order(): void {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);

        $result = Participant_Transfer_Service::transfer_to_existing_order($order, $order, 'uuid-1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('nie mogą być tym samym', $result['error']);
    }

    public function test_add_participant_with_empty_target_weekends_returns_error(): void {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);

        $result = Participant_Transfer_Service::add_participant($order, 10, ['name' => 'John'], []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Nie wybrano żadnego terminu', $result['error']);
    }

    public function test_update_participant_tshirt_updates_attributes_across_items(): void {
        $updated_meta = [];
        $item1 = Mockery::mock('WC_Order_Item_Product');
        $item1->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-1', 'name' => 'Anton', 'tshirt_size' => 'M', 'tshirt_cut' => 'men'],
        ]);
        $item1->shouldReceive('update_meta_data')->with('_ak_participants', Mockery::on(function($val) use (&$updated_meta) {
            $updated_meta = $val;
            return true;
        }))->once();
        $item1->shouldReceive('add_meta_data')->andReturnNull();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item1]);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('update_meta_data')->with('_ak_balance_history', Mockery::any())->once();
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        $result = Participant_Transfer_Service::update_participant_tshirt($order, 'uuid-1', 'XL', 'women');

        $this->assertTrue($result['success']);
        $this->assertEquals('XL', $result['new_size']);
        $this->assertEquals('women', $result['new_cut']);
        $this->assertEquals('XL', $updated_meta[0]['tshirt_size']);
        $this->assertEquals('women', $updated_meta[0]['tshirt_cut']);
    }

    public function test_update_participant_data_updates_name_contact_and_tshirt(): void {
        $updated_meta = [];
        $item1 = Mockery::mock('WC_Order_Item_Product');
        $item1->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-1', 'name' => 'Anton', 'email' => 'old@test.com', 'phone' => '111', 'tshirt_size' => 'M', 'tshirt_cut' => 'men'],
        ]);
        $item1->shouldReceive('update_meta_data')->with('_ak_participants', Mockery::on(function($val) use (&$updated_meta) {
            $updated_meta = $val;
            return true;
        }))->once();
        $item1->shouldReceive('add_meta_data')->andReturnNull();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item1]);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('update_meta_data')->with('_ak_balance_history', Mockery::any())->once();
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        $data = [
            'name'        => 'Anton V',
            'email'       => 'new@test.com',
            'phone'       => '+48 500 600 700',
            'tshirt_size' => 'XL',
            'tshirt_cut'  => 'women',
        ];

        $result = Participant_Transfer_Service::update_participant_data($order, 'uuid-1', $data);

        $this->assertTrue($result['success']);
        $this->assertEquals('Anton V', $result['participant_name']);
        $this->assertEquals('Anton V', $updated_meta[0]['name']);
        $this->assertEquals('new@test.com', $updated_meta[0]['email']);
        $this->assertEquals('+48 500 600 700', $updated_meta[0]['phone']);
        $this->assertEquals('XL', $updated_meta[0]['tshirt_size']);
        $this->assertEquals('women', $updated_meta[0]['tshirt_cut']);
    }

    public function test_transfer_to_existing_order_recalculates_both_orders(): void {
        $item1 = Mockery::mock('WC_Order_Item_Product');
        $item1->shouldIgnoreMissing();
        $item1->shouldReceive('get_id')->andReturn(11);
        $item1->shouldReceive('get_product_id')->andReturn(101);
        $item1->shouldReceive('get_name')->andReturn('Weekend 1');
        $item1->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-1', 'name' => 'Anton', 'tshirt_size' => 'M', 'tshirt_cut' => 'men'],
        ]);
        $item1->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);
        $item1->shouldReceive('update_meta_data')->with('_ak_participants', [])->once();
        $item1->shouldReceive('add_meta_data')->andReturnNull();

        $source_order = Mockery::mock('WC_Order');
        $source_order->shouldIgnoreMissing();
        $source_order->shouldReceive('get_id')->andReturn(100);
        $source_order->shouldReceive('get_items')->with('line_item')->andReturn([$item1]);
        $source_order->shouldReceive('get_meta')->with('_ak_transferred_to_orders')->andReturn([]);
        $source_order->shouldReceive('get_meta')->with('_ak_transferred_to_order')->andReturn(0);
        $source_order->shouldReceive('update_meta_data')->with('_ak_transferred_to_orders', [200])->once();
        $source_order->shouldReceive('update_meta_data')->with('_ak_transferred_to_order', 200)->once();
        $source_order->shouldReceive('add_order_note')->atLeast()->once();
        $source_order->shouldReceive('save')->atLeast()->once();

        $target_item = Mockery::mock('WC_Order_Item_Product');
        $target_item->shouldIgnoreMissing();
        $target_item->shouldReceive('get_id')->andReturn(22);
        $target_item->shouldReceive('get_product_id')->andReturn(101);
        $target_item->shouldReceive('get_name')->andReturn('Weekend 1');
        $target_item->shouldReceive('get_meta')->with('_ak_participants')->andReturn([]);
        $target_item->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);
        $target_item->shouldReceive('update_meta_data')->with('_ak_participants', Mockery::type('array'))->once();
        $target_item->shouldReceive('add_meta_data')->andReturnNull();

        $target_order = Mockery::mock('WC_Order');
        $target_order->shouldIgnoreMissing();
        $target_order->shouldReceive('get_id')->andReturn(200);
        $target_order->shouldReceive('get_items')->with('line_item')->andReturn([$target_item]);
        $target_order->shouldReceive('get_meta')->with('_ak_transferred_from_orders')->andReturn([]);
        $target_order->shouldReceive('get_meta')->with('_ak_transferred_from_order')->andReturn(0);
        $target_order->shouldReceive('update_meta_data')->with('_ak_transferred_from_orders', [100])->once();
        $target_order->shouldReceive('update_meta_data')->with('_ak_transferred_from_order', 100)->once();
        $target_order->shouldReceive('add_order_note')->atLeast()->once();
        $target_order->shouldReceive('save')->atLeast()->once();

        // Stub Order_Recalculator::recalculate via brain monkey / mocking or test execution
        // Since Order_Recalculator is a real class, let's setup required stubs or expectations
        $source_order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn(1000.0);
        $source_order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn(1000.0);
        $source_order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $source_order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn([]);
        $source_order->shouldReceive('get_meta')->with('_ak_coupon_types')->andReturn('');
        $source_order->shouldReceive('get_total')->andReturn(1000.0);
        $source_order->shouldReceive('get_date_created')->andReturn(null);
        $source_order->shouldReceive('remove_item')->andReturnNull();
        $source_order->shouldReceive('update_status')->andReturnNull();
        $source_order->shouldReceive('update_meta_data')->andReturnNull();

        $target_order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn(500.0);
        $target_order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn(500.0);
        $target_order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $target_order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn([]);
        $target_order->shouldReceive('get_meta')->with('_ak_coupon_types')->andReturn('');
        $target_order->shouldReceive('get_total')->andReturn(500.0);
        $target_order->shouldReceive('get_date_created')->andReturn(null);
        $target_order->shouldReceive('calculate_totals')->andReturnNull();
        $target_order->shouldReceive('get_coupons')->andReturn([]);
        $target_order->shouldReceive('get_fees')->andReturn([]);
        $target_order->shouldReceive('update_meta_data')->with('_ak_recalculated_total', Mockery::any())->atLeast()->once();
        $target_order->shouldReceive('update_meta_data')->with('_ak_balance_amount', Mockery::any())->atLeast()->once();
        $target_order->shouldReceive('update_meta_data')->with('_ak_balance_status', Mockery::any())->atLeast()->once();
        $target_order->shouldReceive('update_meta_data')->with('_ak_pricing_breakdown', Mockery::any())->atLeast()->once();
        $target_order->shouldReceive('update_meta_data')->with('_ak_balance_history', Mockery::any())->atLeast()->once();

        $target_item->shouldReceive('set_quantity')->andReturnNull();
        $target_item->shouldReceive('set_subtotal')->andReturnNull();
        $target_item->shouldReceive('set_total')->andReturnNull();

        Functions\when('wc_get_product')->justReturn(null);
        Functions\when('get_post')->justReturn((object)['ID' => 10, 'post_title' => 'Set 10']);
        Functions\when('get_post_meta')->justReturn(null);
        Functions\when('get_field')->alias(function($key) {
            if ($key === 'set_pricing_matrix') {
                return [
                    ['package_size' => 1, 'round_1_price' => 500.0, 'round_1_price_g5' => 450.0, 'round_1_price_g10' => 400.0],
                ];
            }
            return [];
        });

        $result = Participant_Transfer_Service::transfer_to_existing_order($source_order, $target_order, 'uuid-1', 101);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('source_recalc', $result);
        $this->assertArrayHasKey('target_recalc', $result);
    }

    public function test_transfer_type_constants_and_choices(): void {
        $this->assertEquals('new', Participant_Transfer_Service::TRANSFER_TYPE_NEW);
        $this->assertEquals('existing', Participant_Transfer_Service::TRANSFER_TYPE_EXISTING);

        $choices = Participant_Transfer_Service::get_transfer_types();
        $this->assertArrayHasKey('new', $choices);
        $this->assertArrayHasKey('existing', $choices);
    }
}
