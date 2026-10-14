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
        $order->shouldReceive('get_meta')->with('_ak_initial_payment_recorded')->andReturn('yes');
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        Order_Recalculator::mark_settled($order, 'Wpłacono przelewem');

        // Settlement is only a flag: total_paid is NOT overwritten with order total!
        $this->assertArrayNotHasKey('_ak_total_paid', $meta);
        $this->assertEquals('1', $meta['_ak_manually_settled']);
        $this->assertEquals(4800.00, $meta['_ak_manually_settled_total']);
        $this->assertEquals(0.0, $meta['_ak_balance_amount']);
        $this->assertEquals(Order_Recalculator::BALANCE_SETTLED, $meta['_ak_balance_status']);
    }

    public function test_mark_unsettled_restores_pre_settled_paid(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(777);
        $order->shouldReceive('get_total')->andReturn('4800.00');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('2400.00');
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('2400.00');
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('delete_meta_data')->with('_ak_manually_settled')->once();
        $order->shouldReceive('delete_meta_data')->with('_ak_manually_settled_total')->once();
        $order->shouldReceive('update_status')->once();
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        $result = Order_Recalculator::mark_unsettled($order);

        $this->assertEquals(Order_Recalculator::BALANCE_OVERPAID, $result['status']);
        $this->assertEquals(-2400.00, $result['balance_amount']);
        $this->assertEquals(2400.00, $meta['_ak_total_paid']);
    }

    public function test_record_initial_order_state_saves_breakdown_and_balance_for_paid_order(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1234);
        $order->shouldReceive('get_total')->andReturn('3600.00');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('get_date_paid')->andReturn(null);
        $order->shouldReceive('get_transaction_id')->andReturn('txn_123');
        $order->shouldReceive('get_payment_method')->andReturn('p24');
        $order->shouldReceive('get_payment_method_title')->andReturn('Przelewy24');
        $order->shouldReceive('is_paid')->andReturn(true);
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('get_meta')->andReturnUsing(function($k) use (&$meta) {
            return $meta[$k] ?? null;
        });
        $order->shouldReceive('save')->once();

        Order_Recalculator::record_initial_order_state($order);

        $this->assertEquals(3600.00, $meta['_ak_original_total']);
        $this->assertEquals(3600.00, $meta['_ak_total_paid']);
        $this->assertEquals(0.0, $meta['_ak_balance_amount']);
        $this->assertEquals(Order_Recalculator::BALANCE_SETTLED, $meta['_ak_balance_status']);
        $this->assertEquals('yes', $meta['_ak_initial_payment_recorded']);
        $this->assertEquals('Przelewy24', $meta['_ak_initial_payment_method']);
        $this->assertEquals('p24', $meta['_ak_initial_payment_method_id']);
        $this->assertEquals(3600.00, $meta['_ak_initial_payment_amount']);
        $this->assertEquals('txn_123', $meta['_ak_initial_payment_txn_id']);
        $this->assertStringContainsString('SZCZEGÓŁOWA KALKULACJA CENY (NOWE ZAMÓWIENIE)', $meta['_ak_pricing_breakdown']);
    }

    public function test_record_initial_order_state_saves_unpaid_when_order_is_not_paid(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(1235);
        $order->shouldReceive('get_total')->andReturn('3600.00');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('is_paid')->andReturn(false);
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('get_meta')->andReturnUsing(function($k) use (&$meta) {
            return $meta[$k] ?? null;
        });
        $order->shouldReceive('save')->once();

        Order_Recalculator::record_initial_order_state($order);

        $this->assertEquals(3600.00, $meta['_ak_original_total']);
        $this->assertEquals(0.0, $meta['_ak_total_paid']);
        $this->assertEquals(3600.00, $meta['_ak_balance_amount']);
        $this->assertEquals(Order_Recalculator::BALANCE_UNPAID, $meta['_ak_balance_status']);
        $this->assertFalse(isset($meta['_ak_initial_payment_recorded']));
        $this->assertStringContainsString('Nieopłacone (oczekuje na płatność)', $meta['_ak_pricing_breakdown']);
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

    public function test_recalculate_when_headcount_is_zero_and_unpaid_settles_without_overpayment(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(4507);
        $order->shouldReceive('get_status')->andReturn('pending');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('0.00');
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('750.00');
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

        $result = Order_Recalculator::recalculate($order, ['reason' => 'Anulowano nieopłacone zamówienie']);

        $this->assertEquals(Order_Recalculator::BALANCE_SETTLED, $result['status']);
        $this->assertEquals(0.0, $result['balance_amount']);
        $this->assertEquals(0, $result['headcount']);
        $this->assertStringContainsString('BILANS: Uregulowane (brak zaległości i wpłat)', $meta['_ak_pricing_breakdown']);
        $this->assertStringContainsString('Uregulowane (brak wpłat)', $meta['_ak_balance_history']);
    }

    public function test_recalculate_when_all_cancelled_with_accumulated_retained_tshirt_fees(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(4507);
        $order->shouldReceive('get_status')->andReturn('pending');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('0.00');
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('750.00');
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn(99);
        $item->shouldReceive('get_product_id')->andReturn(101);
        $item->shouldReceive('get_name')->andReturn('Weekend 1');
        $item->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);
        $item->shouldReceive('get_meta')->with('_ak_participants')->andReturn([]); // 0 participants left

        // Pre-existing fee from 1st cancellation (60 PLN)
        $fee = new \WC_Order_Item_Fee();
        $fee->set_name('Koszt wyprodukowanej koszulki');
        $fee->set_amount(60.0);
        $fee->set_total(60.0);
        $fee->add_meta_data('_ak_is_tshirt_fee', '1');

        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item]);
        $order->shouldReceive('get_items')->with('fee')->andReturn([$fee]);
        $order->shouldReceive('get_items')->with('coupon')->andReturn([]);
        $order->shouldReceive('remove_item')->with(99)->once();
        $order->shouldReceive('calculate_totals')->with(false)->once();
        // Crucial: WooCommerce total must be updated to 120.00 (not 810.00 or 0.00)
        $order->shouldReceive('set_total')->with(120.0)->once();
        $order->shouldReceive('update_status')->with('cancelled', Mockery::any())->once();
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        // Cancelling last participant, retaining 2nd T-shirt (60 PLN) -> total fees = 120 PLN
        $result = Order_Recalculator::recalculate($order, [
            'reason' => 'Anulowanie z zatrzymaniem koszulki',
            'retained_tshirt_cost' => 60.0,
        ]);

        $this->assertEquals(Order_Recalculator::BALANCE_UNPAID, $result['status']);
        $this->assertEquals(120.0, $result['balance_amount']);
        $this->assertEquals(0, $result['headcount']);
        $this->assertEquals(120.0, $fee->get_total());
        $this->assertEquals(120.0, $meta['_ak_recalculated_total']);
        $this->assertStringContainsString('Naliczone opłaty', $meta['_ak_pricing_breakdown']);
        $this->assertStringContainsString('Koszt wyprodukowanej koszulki', $meta['_ak_pricing_breakdown']);
        $this->assertStringContainsString('120', $meta['_ak_pricing_breakdown']);
        $this->assertStringContainsString('Nieopłacone', $meta['_ak_pricing_breakdown']);
    }

    public function test_recalculate_multi_cohorts_sorted_by_package_size_and_priced_independently(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(888);
        $order->shouldReceive('get_status')->andReturn('processing');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('3550.00');
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('3550.00');
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(3);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('calculate_totals')->with(false)->andReturnNull();
        $order->shouldReceive('get_total')->andReturn('3550.00');
        $order->shouldReceive('get_items')->with('fee')->andReturn([]);
        $order->shouldReceive('get_items')->with('coupon')->andReturn([]);
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        // Line item 1: Weekend 101 has Alice, Bob, Charlie
        $item101 = Mockery::mock('WC_Order_Item_Product');
        $item101->shouldReceive('get_id')->andReturn(1);
        $item101->shouldReceive('get_product_id')->andReturn(101);
        $item101->shouldReceive('get_name')->andReturn('Weekend 1');
        $item101->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);
        $item101->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-charlie', 'name' => 'Charlie'], // 1 weekend only
            ['id' => 'uuid-alice', 'name' => 'Alice'],
            ['id' => 'uuid-bob', 'name' => 'Bob'],
        ]);
        $item101->shouldReceive('set_quantity')->with(3)->once();
        $item101->shouldReceive('set_subtotal')->once();
        $item101->shouldReceive('set_total')->once();
        $item101->shouldReceive('update_meta_data')->with('_ak_headcount', 3)->once();

        // Line item 2: Weekend 102 has only Alice and Bob
        $item102 = Mockery::mock('WC_Order_Item_Product');
        $item102->shouldReceive('get_id')->andReturn(2);
        $item102->shouldReceive('get_product_id')->andReturn(102);
        $item102->shouldReceive('get_name')->andReturn('Weekend 2');
        $item102->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);
        $item102->shouldReceive('get_meta')->with('_ak_participants')->andReturn([
            ['id' => 'uuid-alice', 'name' => 'Alice'],
            ['id' => 'uuid-bob', 'name' => 'Bob'],
        ]);
        $item102->shouldReceive('set_quantity')->with(2)->once();
        $item102->shouldReceive('set_subtotal')->once();
        $item102->shouldReceive('set_total')->once();
        $item102->shouldReceive('update_meta_data')->with('_ak_headcount', 2)->once();

        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item101, $item102]);

        Functions\when('wc_get_product')->alias(function($id) {
            $prod = Mockery::mock('WC_Product');
            $prod->shouldReceive('get_name')->andReturn('Termin #' . $id);
            return $prod;
        });
        Functions\when('get_field')->justReturn(null);
        Functions\when('get_post')->justReturn((object)['ID' => 10, 'post_title' => 'Obóz Gimnastyczny']);
        Functions\when('get_post_meta')->alias(function($post_id, $key) {
            // Price matrix:
            // 2 weekends package: 1400.00 PLN per person
            // 1 weekend package: 750.00 PLN per person
            if (str_starts_with($key, 'price_2w_round1_ind')) {
                return '1400.00';
            }
            if (str_starts_with($key, 'price_1w_round1_ind')) {
                return '750.00';
            }
            return null;
        });

        $result = Order_Recalculator::recalculate($order, ['reason' => 'Przeliczenie wielu pakietów']);

        // Check headcounts and totals:
        $this->assertEquals(Order_Recalculator::BALANCE_SETTLED, $result['status']);
        $this->assertEquals(3, $result['headcount']);
        $this->assertEquals(3550.00, $result['recalculated_total']); // (2 * 1400) + (1 * 750) = 3550

        // Verify that Pakiet 1 is the 2-weekend package (Alice, Bob) and Pakiet 2 is 1-weekend (Charlie)
        $breakdown = $meta['_ak_pricing_breakdown'];
        $pakiet1_pos = strpos($breakdown, 'Pakiet 1');
        $pakiet2_pos = strpos($breakdown, 'Pakiet 2');
        $this->assertNotFalse($pakiet1_pos);
        $this->assertNotFalse($pakiet2_pos);
        $this->assertTrue($pakiet1_pos < $pakiet2_pos);

        // Pakiet 1 should have 2 weekends and Alice, Bob
        $this->assertMatchesRegularExpression('/Pakiet 1:.*Liczba weekendów: 2/s', $breakdown);
        // Pakiet 2 should have 1 weekend and Charlie
        $this->assertMatchesRegularExpression('/Pakiet 2:.*Liczba weekendów: 1/s', $breakdown);
    }

    public function test_recalculate_expires_manual_settlement_when_order_total_changes(): void {
        $meta = [
            '_ak_manually_settled'       => '1',
            '_ak_manually_settled_total' => '750.00',
        ];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(888);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('get_date_created')->andReturn(null);
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('750.00');
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('750.00');
        $order->shouldReceive('get_meta')->with('_ak_original_headcount')->andReturn(1);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_meta')->with('_ak_manually_settled')->andReturn('1');
        $order->shouldReceive('get_meta')->with('_ak_manually_settled_total')->andReturn('750.00');

        $item = Mockery::mock('WC_Order_Item_Product');
        $item->shouldReceive('get_id')->andReturn(99);
        $item->shouldReceive('get_product_id')->andReturn(101);
        $item->shouldReceive('get_name')->andReturn('Weekend 1');
        $item->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);
        $item->shouldReceive('get_meta')->with('_ak_participants')->andReturn([]); // 0 participants -> total becomes 0

        $order->shouldReceive('get_items')->with('line_item')->andReturn([$item]);
        $order->shouldReceive('remove_item')->with(99)->once();
        $order->shouldReceive('update_status')->with('cancelled', Mockery::any())->once();
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('delete_meta_data')->andReturnUsing(function($k) use (&$meta) {
            unset($meta[$k]);
        });
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        $result = Order_Recalculator::recalculate($order, ['reason' => 'Anulowano uczestnika']);

        // Manual settlement must expire because total changed from 750 to 0 (overpayment)
        $this->assertArrayNotHasKey('_ak_manually_settled', $meta);
        $this->assertArrayNotHasKey('_ak_manually_settled_total', $meta);
        $this->assertEquals(Order_Recalculator::BALANCE_OVERPAID, $result['status']);
        $this->assertEquals(-750.00, $result['balance_amount']);
    }
}

