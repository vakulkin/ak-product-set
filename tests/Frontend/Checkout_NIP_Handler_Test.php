<?php

namespace AK_Set\Tests\Frontend;

use AK_Set\Frontend\Checkout_NIP_Handler;
use AK_Set\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

class Checkout_NIP_Handler_Test extends TestCase {

    public function test_add_billing_nip_field_appends_nip_and_company_data(): void {
        $handler = new Checkout_NIP_Handler();
        $fields = [
            'billing_first_name' => ['label' => 'First Name'],
        ];

        $updated = $handler->add_billing_nip_field($fields);

        $this->assertArrayHasKey('billing_nip', $updated);
        $this->assertEquals(120, $updated['billing_nip']['priority']);
        $this->assertEquals('NIP (faktura VAT)', $updated['billing_nip']['label']);

        $this->assertArrayHasKey('billing_company_data', $updated);
        $this->assertEquals(121, $updated['billing_company_data']['priority']);
        $this->assertEquals('textarea', $updated['billing_company_data']['type']);
        $this->assertEquals('Dane firmy do faktury', $updated['billing_company_data']['label']);
    }

    public function test_save_nip_to_order_cleans_and_stores_meta_and_company_data(): void {
        $meta = [];
        $billing_company = '';
        $billing_address = '';

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('get_billing_company')->andReturnUsing(function() use (&$billing_company) {
            return $billing_company;
        });
        $order->shouldReceive('set_billing_company')->andReturnUsing(function($v) use (&$billing_company) {
            $billing_company = $v;
        });
        $order->shouldReceive('get_billing_address_1')->andReturnUsing(function() use (&$billing_address) {
            return $billing_address;
        });
        $order->shouldReceive('set_billing_address_1')->andReturnUsing(function($v) use (&$billing_address) {
            $billing_address = $v;
        });

        $_POST['billing_nip'] = 'PL 525-234-40-78';
        $_POST['billing_company_data'] = "ACME Sp. z o.o., ul. Marszałkowska 10, 00-001 Warszawa";

        $handler = new Checkout_NIP_Handler();
        $handler->save_nip_to_order($order, []);

        unset($_POST['billing_nip'], $_POST['billing_company_data']);

        $this->assertEquals('5252344078', $meta['_billing_nip']);
        $this->assertEquals('5252344078', $meta['billing_nip']);
        $this->assertEquals('5252344078', $meta['_billing_vat_id']);
        $this->assertEquals("ACME Sp. z o.o., ul. Marszałkowska 10, 00-001 Warszawa", $meta['_billing_company_data']);
        $this->assertEquals("ACME Sp. z o.o.", $billing_company);
        $this->assertEquals("ACME Sp. z o.o., ul. Marszałkowska 10, 00-001 Warszawa", $billing_address);
    }

    public function test_save_nip_to_order_appends_to_existing_billing_address(): void {
        $meta = [];
        $billing_company = 'Existing Corp';
        $billing_address = 'ul. Główna 1';

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('update_meta_data')->andReturnUsing(function($k, $v) use (&$meta) {
            $meta[$k] = $v;
        });
        $order->shouldReceive('get_billing_company')->andReturnUsing(function() use (&$billing_company) {
            return $billing_company;
        });
        $order->shouldReceive('get_billing_address_1')->andReturnUsing(function() use (&$billing_address) {
            return $billing_address;
        });
        $order->shouldReceive('set_billing_address_1')->andReturnUsing(function($v) use (&$billing_address) {
            $billing_address = $v;
        });

        $handler = new Checkout_NIP_Handler();
        $handler->save_nip_to_order($order, [
            'billing_nip'          => '5252344078',
            'billing_company_data' => 'ACME, ul. Testowa 2, 00-002 Warszawa',
        ]);

        $this->assertEquals('Existing Corp', $billing_company);
        $this->assertEquals('ul. Główna 1, ACME, ul. Testowa 2, 00-002 Warszawa', $billing_address);
    }
}
