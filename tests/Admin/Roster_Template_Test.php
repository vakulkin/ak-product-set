<?php

namespace AK_Set\Tests\Admin;

use AK_Set\Tests\TestCase;
use Brain\Monkey\Functions;

class Roster_Template_Test extends TestCase {

    public function test_roster_page_template_renders_without_defined_variables(): void {
        Functions\stubs([
            'admin_url'     => 'http://example.com/wp-admin/admin.php',
            'wp_nonce_url'  => 'http://example.com/wp-admin/admin.php?nonce=123',
            'esc_attr'      => function($v) { return $v; },
            'esc_html_e'    => function($v) { echo $v; },
            'esc_attr_e'    => function($v) { echo $v; },
            'selected'      => function() {},
            'submit_button' => function() {},
        ]);

        $template_path = dirname(dirname(__DIR__)) . '/templates/admin/roster-page.php';

        ob_start();
        include $template_path;
        $output = ob_get_clean();

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('Rejestr uczestników', $output);
        $this->assertStringContainsString('Pobierz CSV', $output);
    }

    public function test_roster_page_template_renders_participant_balance_badges(): void {
        Functions\stubs([
            'admin_url'                => 'http://example.com/wp-admin/admin.php',
            'wp_nonce_url'             => 'http://example.com/wp-admin/admin.php?nonce=123',
            'esc_attr'                 => function($v) { return $v; },
            'esc_html_e'               => function($v) { echo $v; },
            'esc_attr_e'               => function($v) { echo $v; },
            'selected'                 => function() {},
            'submit_button'            => function() {},
            'current_user_can'         => function() { return true; },
            'wc_get_order_status_name' => function($s) { return $s; },
            'sanitize_html_class'      => function($s) { return $s; },
        ]);

        $selected_weekend = 101;
        $weekends         = [101 => 'Weekend 1'];
        $participants     = [
            [
                'participant_id' => 'uuid-1',
                'order_item_id'  => 1,
                'order_id'       => 201,
                'order_status'   => 'processing',
                'balance_status' => 'underpaid',
                'balance_amount' => 300.0,
                'date_paid'      => '',
                'payment_method' => 'BLIK',
                'set_name'       => 'Pakiet 2',
                'name'           => 'Jan Kowalski',
                'email'          => 'jan@example.com',
                'phone'          => '123456789',
                'tshirt_size'    => 'M',
                'tshirt_cut'     => 'men',
            ],
            [
                'participant_id' => 'uuid-2',
                'order_item_id'  => 2,
                'order_id'       => 202,
                'order_status'   => 'completed',
                'balance_status' => 'overpaid',
                'balance_amount' => -150.0,
                'date_paid'      => '2026-05-01',
                'payment_method' => 'P24',
                'set_name'       => 'Pakiet 1',
                'name'           => 'Anna Nowak',
                'email'          => 'anna@example.com',
                'phone'          => '987654321',
                'tshirt_size'    => 'S',
                'tshirt_cut'     => 'women',
            ],
        ];

        $template_path = dirname(dirname(__DIR__)) . '/templates/admin/roster-page.php';

        ob_start();
        include $template_path;
        $output = ob_get_clean();

        $this->assertStringContainsString('Rozliczenie', $output);
        $this->assertStringContainsString('Niedopłata', $output);
        $this->assertStringContainsString('Nadpłata', $output);
    }
}
