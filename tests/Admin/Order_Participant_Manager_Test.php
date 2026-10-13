<?php

namespace AK_Set\Tests\Admin;

use AK_Set\Admin\Order_Participant_Manager;
use AK_Set\Admin\Roster_Query;
use AK_Set\Order\Order_Recalculator;
use AK_Set\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

class Order_Participant_Manager_Test extends TestCase {

    protected function tearDown(): void {
        Roster_Query::reset_cache();
        parent::tearDown();
    }

    public function test_render_meta_box_displays_balance_and_overpayment_when_all_participants_removed(): void {
        Functions\stubs([
            'admin_url'          => 'http://example.com/wp-admin/admin.php',
            'esc_attr'           => function($v) { return $v; },
            'esc_html_e'         => function($v) { echo $v; },
            'esc_attr_e'         => function($v) { echo $v; },
            'wp_create_nonce'    => function() { return 'test-nonce'; },
        ]);

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(100);
        $order->shouldReceive('get_total')->andReturn('0.00');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]); // 0 line items!
        $order->shouldReceive('get_meta')->with('_ak_order_decomposed')->andReturn('1');
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('3000.00');
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('3000.00');
        $order->shouldReceive('get_meta')->with('_ak_balance_status')->andReturn(Order_Recalculator::BALANCE_OVERPAID);
        $order->shouldReceive('get_meta')->with('_ak_balance_amount')->andReturn(-3000.00);
        $order->shouldReceive('get_meta')->with('_ak_pricing_breakdown')->andReturn("BILANS: Nadpłata -3000.00 zł");
        $order->shouldReceive('get_meta')->with('_ak_transferred_to_orders')->andReturn([101]);
        $order->shouldReceive('get_meta')->with('_ak_transferred_to_order')->andReturn(101);
        $order->shouldReceive('get_meta')->with('_ak_transferred_from_order')->andReturn(0);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn("2026-10-13: Przeniesiono uczestnika");

        $manager = new Order_Participant_Manager();

        ob_start();
        $manager->render_meta_box($order);
        $output = ob_get_clean();

        // Must display overpayment badge and amount
        $this->assertStringContainsString('Nadpłata:', $output);
        $this->assertStringContainsString('(do zwrotu)', $output);
        $this->assertStringContainsString('Oznacz jako uregulowane', $output);
        // Must display cross-reference to destination order #101
        $this->assertStringContainsString('#101', $output);
        // Must show message about all participants moved / cancelled
        $this->assertStringContainsString('Brak aktywnych terminów uczestników w tym zamówieniu', $output);
        // Must NOT output the "To zamówienie nie zawiera pozycji rozbitych pakietów" message
        $this->assertStringNotContainsString('To zamówienie nie zawiera pozycji rozbitych pakietów AK Zestawów.', $output);
    }

    public function test_render_meta_box_bails_for_non_ak_orders(): void {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);
        $order->shouldReceive('get_meta')->andReturn(null);

        $manager = new Order_Participant_Manager();

        ob_start();
        $manager->render_meta_box($order);
        $output = ob_get_clean();
        $this->assertStringContainsString('To zamówienie nie zawiera pozycji rozbitych pakietów AK Zestawów.', $output);
    }

    public function test_render_meta_box_normalizes_zero_balance_to_settled(): void {
        Functions\stubs([
            'admin_url'       => 'http://example.com/wp-admin/admin.php',
            'esc_attr'        => function($v) { return $v; },
            'esc_html_e'      => function($v) { echo $v; },
            'esc_attr_e'      => function($v) { echo $v; },
            'wp_create_nonce' => function() { return 'test-nonce'; },
            'get_post'        => function($id) { return (object)['ID' => $id, 'post_title' => 'Test Set']; },
            'get_field'       => function($f, $id) { return []; },
        ]);

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(200);
        $order->shouldReceive('get_total')->andReturn('750.00');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);
        $order->shouldReceive('get_meta')->with('_ak_order_decomposed')->andReturn('1');
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('750.00');
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('750.00');
        // Suppose meta was erroneously stored as overpaid with 0.00 balance:
        $order->shouldReceive('get_meta')->with('_ak_balance_status')->andReturn(Order_Recalculator::BALANCE_OVERPAID);
        $order->shouldReceive('get_meta')->with('_ak_balance_amount')->andReturn(0.00);
        $order->shouldReceive('get_meta')->with('_ak_pricing_breakdown')->andReturn('');
        $order->shouldReceive('get_meta')->with('_ak_transferred_to_orders')->andReturn([]);
        $order->shouldReceive('get_meta')->with('_ak_transferred_to_order')->andReturn(0);
        $order->shouldReceive('get_meta')->with('_ak_transferred_from_order')->andReturn(0);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);

        $manager = new Order_Participant_Manager();

        ob_start();
        $manager->render_meta_box($order);
        $output = ob_get_clean();

        // Must display settled badge and NOT overpayment or (do zwrotu)
        $this->assertStringContainsString('Rozliczone (brak zaległości)', $output);
        $this->assertStringNotContainsString('(do zwrotu)', $output);
        $this->assertStringNotContainsString('Nadpłata:', $output);
    }

    public function test_render_meta_box_empty_order_has_add_participant_button(): void {
        Functions\stubs([
            'admin_url'       => 'http://example.com/wp-admin/admin.php',
            'esc_attr'        => function($v) { return $v; },
            'esc_html_e'      => function($v) { echo $v; },
            'esc_attr_e'      => function($v) { echo $v; },
            'wp_create_nonce' => function() { return 'test-nonce'; },
            'get_post'        => function($id) { return (object)['ID' => $id, 'post_title' => 'Test Set']; },
            'get_field'       => function($f, $id) { return []; },
        ]);

        $order = Mockery::mock('WC_Order');
        $order->shouldIgnoreMissing();
        $order->shouldReceive('get_id')->andReturn(300);
        $order->shouldReceive('get_total')->andReturn('0.00');
        $order->shouldReceive('get_items')->with('line_item')->andReturn([]);
        $order->shouldReceive('get_meta')->with('_ak_order_decomposed')->andReturn('1');
        $order->shouldReceive('get_meta')->with('_ak_original_total')->andReturn('750.00');
        $order->shouldReceive('get_meta')->with('_ak_total_paid')->andReturn('750.00');
        $order->shouldReceive('get_meta')->with('_ak_balance_status')->andReturn(Order_Recalculator::BALANCE_OVERPAID);
        $order->shouldReceive('get_meta')->with('_ak_balance_amount')->andReturn(-750.00);
        $order->shouldReceive('get_meta')->with('_ak_pricing_breakdown')->andReturn('');
        $order->shouldReceive('get_meta')->with('_ak_transferred_to_orders')->andReturn([]);
        $order->shouldReceive('get_meta')->with('_ak_transferred_to_order')->andReturn(0);
        $order->shouldReceive('get_meta')->with('_ak_transferred_from_order')->andReturn(0);
        $order->shouldReceive('get_meta')->with('_ak_balance_history')->andReturn('');
        $order->shouldReceive('get_meta')->with('_ak_parent_set_id')->andReturn(10);

        $manager = new Order_Participant_Manager();

        ob_start();
        $manager->render_meta_box($order);
        $output = ob_get_clean();

        // Must display top primary add participant button
        $this->assertStringContainsString('+ Dodaj uczestnika', $output);
    }
}

