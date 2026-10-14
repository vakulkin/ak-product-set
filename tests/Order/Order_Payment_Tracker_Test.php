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

    public function test_record_initial_payment_skipped_when_internal_status_transition_is_true(): void {
        Order_Payment_Tracker::$is_internal_transition = true;
        try {
            $order = Mockery::mock('WC_Order');
            $order->shouldNotReceive('update_meta_data');
            $order->shouldNotReceive('save');

            $tracker = new Order_Payment_Tracker();
            $tracker->record_initial_payment($order);
            $this->assertTrue(true);
        } finally {
            Order_Payment_Tracker::$is_internal_transition = false;
        }
    }

    public function test_record_initial_payment_prefers_original_total_over_reduced_current_total(): void {
        $meta = [
            '_ak_order_decomposed' => '1',
            '_ak_original_total'   => '750.00',
        ];
        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(4508);
        $order->shouldReceive('get_total')->andReturn('60.00'); // current total after cancellations
        $order->shouldReceive('get_payment_method')->andReturn('p24');
        $order->shouldReceive('get_payment_method_title')->andReturn('Przelewy24');
        $order->shouldReceive('get_transaction_id')->andReturn('');
        $order->shouldReceive('get_date_paid')->andReturn(null);
        $order->shouldReceive('get_meta')->andReturnUsing(function($k) use (&$meta) {
            return $meta[$k] ?? null;
        });
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('add_order_note');
        $order->shouldReceive('save');

        $tracker = new Order_Payment_Tracker();
        $tracker->record_initial_payment($order);

        // Crucial: Must be 750.00, NOT 60.00!
        $this->assertEquals(750.00, $meta['_ak_initial_payment_amount']);
        $this->assertEquals(750.00, $meta['_ak_total_paid']);
    }

    public function test_record_initial_payment_auto_heals_corrupted_amount(): void {
        $meta = [
            '_ak_order_decomposed'          => '1',
            '_ak_original_total'            => '750.00',
            '_ak_initial_payment_recorded'  => 'yes',
            '_ak_initial_payment_amount'    => '60.00', // corrupted by earlier bug
        ];
        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(4508);
        $order->shouldReceive('get_meta')->andReturnUsing(function($k) use (&$meta) {
            return $meta[$k] ?? null;
        });
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('save')->once();

        $tracker = new Order_Payment_Tracker();
        $tracker->record_initial_payment($order);

        $this->assertEquals(750.00, $meta['_ak_initial_payment_amount']);
    }

    public function test_recalculate_heals_corrupted_sixty_pln_and_supports_additional_paid(): void {
        $meta = [
            '_ak_original_total'           => '750.00',
            '_ak_initial_payment_recorded' => 'yes',
            '_ak_initial_payment_amount'   => '60.00', // corrupted
            '_ak_total_paid'               => '60.00', // corrupted
            '_ak_original_headcount'       => '1',
        ];
        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(4508);
        $order->shouldReceive('get_total')->andReturn('750.00');
        $order->shouldReceive('get_status')->andReturn('processing');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);
        $order->shouldReceive('get_items')->with('fee')->andReturn([]);
        $order->shouldReceive('get_meta')->andReturnUsing(function($k) use (&$meta) {
            return $meta[$k] ?? null;
        });
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });

        $result = Order_Recalculator::recalculate($order);

        // Gateway payment must be restored to 750, not 60!
        $this->assertEquals(750.00, $meta['_ak_initial_payment_amount']);
        $this->assertEquals(750.00, $meta['_ak_total_paid']);
        $this->assertEquals(0.0, $meta['_ak_additional_paid']);
        $this->assertEquals(Order_Recalculator::BALANCE_OVERPAID, $result['status']);
        $this->assertEquals(-750.0, $result['balance_amount']);
    }

    public function test_additional_paid_adds_to_gateway_baseline_without_overwriting(): void {
        $meta = [
            '_ak_original_total'           => '750.00',
            '_ak_initial_payment_recorded' => 'yes',
            '_ak_initial_payment_amount'   => '750.00',
            '_ak_additional_paid'          => '100.00', // paid after checkout via BLIK / transfer
            '_ak_total_paid'               => '850.00',
            '_ak_original_headcount'       => '1',
        ];
        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(4509);
        $order->shouldReceive('get_total')->andReturn('850.00');
        $order->shouldReceive('get_status')->andReturn('processing');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);
        $order->shouldReceive('get_items')->with('fee')->andReturn([]);
        $order->shouldReceive('get_meta')->andReturnUsing(function($k) use (&$meta) {
            return $meta[$k] ?? null;
        });
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });

        $result = Order_Recalculator::recalculate($order);

        // Gateway payment stays locked to 750, total paid is 850
        $this->assertEquals(750.00, $meta['_ak_initial_payment_amount']);
        $this->assertEquals(100.00, $meta['_ak_additional_paid']);
        $this->assertEquals(850.00, $meta['_ak_total_paid']);
        $this->assertEquals(Order_Recalculator::BALANCE_OVERPAID, $result['status']);
        $this->assertEquals(-850.0, $result['balance_amount']);
    }
}
