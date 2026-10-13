<?php

namespace AK_Set\Tests\Order;

use AK_Set\Tests\TestCase;
use AK_Set\Order\Order_Recalculator;
use Mockery;
use Brain\Monkey\Functions;

class Order_Recalculator_Test extends TestCase {

    public function test_get_tshirt_cost_defaults_to_50(): void {
        unset($GLOBALS['_wp_options']['ak_set_tshirt_cost']);
        $cost = Order_Recalculator::get_tshirt_cost();
        $this->assertEquals(50.0, $cost);
    }

    public function test_get_tshirt_cost_uses_configured_option(): void {
        update_option('ak_set_tshirt_cost', '65.50');
        $cost = Order_Recalculator::get_tshirt_cost();
        $this->assertEquals(65.50, $cost);
        unset($GLOBALS['_wp_options']['ak_set_tshirt_cost']);
    }

    public function test_get_tshirt_cost_prefers_set_level_override(): void {
        Functions\when('get_field')->alias(function($field, $post_id) {
            if ($field === 'set_tshirt_cost' && $post_id === 42) {
                return '75.00';
            }
            return null;
        });

        update_option('ak_set_tshirt_cost', '50.00');

        $cost_set = Order_Recalculator::get_tshirt_cost(42);
        $cost_default = Order_Recalculator::get_tshirt_cost(99);

        $this->assertEquals(75.00, $cost_set);
        $this->assertEquals(50.00, $cost_default);

        unset($GLOBALS['_wp_options']['ak_set_tshirt_cost']);
    }

    public function test_detect_total_headcount_counts_unique_participant_uuids(): void {
        $item1 = Mockery::mock('WC_Order_Item_Product');
        $item1->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-1', 'name' => 'Alice'],
            ['id' => 'uuid-2', 'name' => 'Bob'],
        ]);

        $item2 = Mockery::mock('WC_Order_Item_Product');
        $item2->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-1', 'name' => 'Alice'], // same participant in second weekend
            ['id' => 'uuid-3', 'name' => 'Charlie'],
        ]);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item1, $item2]);

        $count = Order_Recalculator::detect_total_headcount($order);
        $this->assertEquals(3, $count);
    }

    public function test_mark_settled_clears_balance_and_updates_status(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_total')->andReturn('4800.00');
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('2400.00');
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_meta')->with('_ak_pricing_breakdown')->andReturn('');
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        Order_Recalculator::mark_settled($order, 'Wpłacono przelewem');

        $this->assertEquals(4800.00, $meta['_ak_total_paid']);
        $this->assertEquals(2400.00, $meta['_ak_pre_settled_total_paid']);
        $this->assertEquals('1', $meta['_ak_manually_settled']);
        $this->assertEquals(0.0, $meta['_ak_balance_amount']);
        $this->assertEquals(Order_Recalculator::BALANCE_SETTLED, $meta['_ak_balance_status']);
    }

    public function test_mark_unsettled_restores_pre_settled_paid(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(777);
        $order->shouldReceive('get_total')->andReturn('4800.00');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('get_meta')->with('_ak_pre_settled_total_paid')->andReturn('2400.00');
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('2400.00');
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('2400.00');
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('delete_meta_data')->with('_ak_manually_settled')->once();
        $order->shouldReceive('delete_meta_data')->with('_ak_pre_settled_total_paid')->once();
        $order->shouldReceive('update_status')->once();
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        $result = Order_Recalculator::mark_unsettled($order);

        $this->assertEquals(2400.00, $meta['_ak_total_paid']);
    }

    public function test_record_initial_order_state_saves_breakdown_and_balance(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1234);
        $order->shouldReceive('get_total')->andReturn('3600.00');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('save')->once();

        Order_Recalculator::record_initial_order_state($order);

        $this->assertEquals(3600.00, $meta['_ak_original_total']);
        $this->assertEquals(3600.00, $meta['_ak_total_paid']);
        $this->assertEquals(0.0, $meta['_ak_balance_amount']);
        $this->assertEquals(Order_Recalculator::BALANCE_SETTLED, $meta['_ak_balance_status']);
        $this->assertStringContainsString('SZCZEGÓŁOWA KALKULACJA CENY (NOWE ZAMÓWIENIE)', $meta['_ak_pricing_breakdown']);
    }

    public function test_recalculate_cancels_order_when_zero_participants_remain(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(555);
        $order->shouldReceive('get_total')->andReturn('1500.00');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('1500.00');
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('1500.00');
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn(99);
        $item->shouldReceive('get_product_id')->andReturn(101);
        $item->shouldReceive('get_name')->andReturn('Weekend 1');
        $item->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);
        $item->shouldReceive('get_meta')->with('_ak_participants')->andReturn([]); // 0 participants

        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item]);
        $order->shouldReceive('remove_item')->with(99)->once();
        $order->shouldReceive('update_status')->with('cancelled', Mockery::any())->once();
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        $result = Order_Recalculator::recalculate($order, ['reason' => 'Wszyscy zrezygnowali']);

        $this->assertEquals(Order_Recalculator::BALANCE_OVERPAID, $result['status']);
        $this->assertEquals(-1500.00, $result['balance_amount']);
        $this->assertEquals(0, $result['headcount']);
    }
}
