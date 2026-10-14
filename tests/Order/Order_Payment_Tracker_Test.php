<?php

namespace AK_Set\Tests\Order;

use AK_Set\Order\Order_Payment_Tracker;
use AK_Set\Order\Order_Recalculator;
use AK_Set\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

class Order_Payment_Tracker_Test extends TestCase {

    public function test_record_initial_payment_stores_payment_meta_and_settles_unpaid_order(): void {
        $meta = [];
        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(888);
        $order->shouldReceive('get_total')->andReturn('3000.00');
        $order->shouldReceive('get_payment_method')->andReturn('p24');
        $order->shouldReceive('get_payment_method_title')->andReturn('Przelewy24');
        $order->shouldReceive('get_transaction_id')->andReturn('txn_99999');
        $order->shouldReceive('get_date_paid')->andReturn(null);

        $order->shouldReceive('get_meta')->andReturnUsing(function($k) use (&$meta) {
            return $meta[$k] ?? null;
        });
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once();

        // Setup initially as unpaid decomposed AK order
        $meta['_ak_order_decomposed']   = '1';
        $meta['_ak_balance_status']     = Order_Recalculator::BALANCE_UNPAID;
        $meta['_ak_total_paid']         = '0.00';
        $meta['_ak_balance_amount']     = '3000.00';
        $meta['_ak_pricing_breakdown']  = "Wpłacono: 0,00 zł\nBILANS: Nieopłacone (oczekuje na płatność)";

        $tracker = new Order_Payment_Tracker();
        $tracker->record_initial_payment($order);

        $this->assertEquals('yes', $meta['_ak_initial_payment_recorded']);
        $this->assertEquals('Przelewy24', $meta['_ak_initial_payment_method']);
        $this->assertEquals('p24', $meta['_ak_initial_payment_method_id']);
        $this->assertEquals(3000.00, $meta['_ak_initial_payment_amount']);
        $this->assertEquals('txn_99999', $meta['_ak_initial_payment_txn_id']);
        $this->assertEquals(3000.00, $meta['_ak_total_paid']);
        $this->assertEquals(Order_Recalculator::BALANCE_SETTLED, $meta['_ak_balance_status']);
        $this->assertEquals(0.0, $meta['_ak_balance_amount']);
        $this->assertStringContainsString('Wpłacono: ' . number_format_i18n(3000.00, 2) . ' zł', $meta['_ak_pricing_breakdown']);
        $this->assertStringContainsString('BILANS: Uregulowane (brak zaległości)', $meta['_ak_pricing_breakdown']);
    }

    public function test_record_initial_payment_ignores_non_ak_orders(): void {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->andReturn(null);
        $order->shouldNotReceive('update_meta_data');
        $order->shouldNotReceive('save');

        $tracker = new Order_Payment_Tracker();
        $tracker->record_initial_payment($order);
        $this->assertTrue(true);
    }

    public function test_record_initial_payment_does_not_duplicate_if_already_recorded(): void {
        $meta = [
            '_ak_order_decomposed'          => '1',
            '_ak_initial_payment_recorded' => 'yes',
        ];
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->andReturnUsing(function($k) use ($meta) {
            return $meta[$k] ?? null;
        });
        $order->shouldNotReceive('update_meta_data');
        $order->shouldNotReceive('save');

        $tracker = new Order_Payment_Tracker();
        $tracker->record_initial_payment($order);
        $this->assertTrue(true);
    }
}
