<?php

namespace AK_Set\Tests\Frontend;

use AK_Set\Frontend\Checkout_NIP_Handler;
use AK_Set\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;

class Checkout_NIP_Handler_Test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Functions\stubs([
            'WC' => function() { return null; },
        ]);
    }

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

    public function test_ensure_checkout_nip_fields_injects_nip_even_if_other_plugin_stripped_billing(): void {
        $handler = new Checkout_NIP_Handler();

        // Simulate another plugin that stripped everything down to 4 fields
        $fields = [
            'billing' => [
                'billing_first_name' => ['label' => 'First Name'],
                'billing_last_name'  => ['label' => 'Last Name'],
                'billing_phone'      => ['label' => 'Phone'],
                'billing_email'      => ['label' => 'Email'],
            ],
            'shipping' => [],
        ];

        $result = $handler->ensure_checkout_nip_fields($fields);

        $this->assertArrayHasKey('billing_nip', $result['billing']);
        $this->assertArrayHasKey('billing_company_data', $result['billing']);
        $this->assertArrayHasKey('billing_first_name', $result['billing']);
    }

    public function test_get_checkout_field_value_reads_from_user_meta(): void {
        $handler = new Checkout_NIP_Handler();

        $GLOBALS['_wp_current_user_id'] = 42;
        update_user_meta(42, 'billing_nip', '5252344078');
        update_user_meta(42, 'billing_company_data', 'My Company Sp. z o.o.');

        $nip_val = $handler->get_checkout_field_value(null, 'billing_nip');
        $company_val = $handler->get_checkout_field_value(null, 'billing_company_data');

        $this->assertEquals('5252344078', $nip_val);
        $this->assertEquals('My Company Sp. z o.o.', $company_val);

        unset($GLOBALS['_wp_current_user_id'], $GLOBALS['_wp_user_meta'][42]);
    }

    public function test_get_checkout_field_value_reads_from_wc_session(): void {
        $session = Mockery::mock();
        $session->shouldReceive('get')->with('billing_nip')->andReturn('1234567890');
        $session->shouldReceive('get')->with('billing_company_data')->andReturn('ACME Session');

        $wc = (object) ['session' => $session];
        Functions\when('WC')->justReturn($wc);

        $handler = new Checkout_NIP_Handler();

        $this->assertEquals('1234567890', $handler->get_checkout_field_value(null, 'billing_nip'));
        $this->assertEquals('ACME Session', $handler->get_checkout_field_value(null, 'billing_company_data'));
    }

    public function test_update_user_meta_on_checkout_persists_nip_and_company_data(): void {
        $handler = new Checkout_NIP_Handler();

        $posted = [
            'billing_nip'          => 'PL 525-234-40-78',
            'billing_company_data' => 'ACME Polska Sp. z o.o.',
        ];

        $handler->update_user_meta_on_checkout(77, $posted);

        $this->assertEquals('5252344078', get_user_meta(77, 'billing_nip', true));
        $this->assertEquals('ACME Polska Sp. z o.o.', get_user_meta(77, 'billing_company_data', true));

        unset($GLOBALS['_wp_user_meta'][77]);
    }

    public function test_add_customer_meta_fields_adds_nip_and_company_data_to_user_profile(): void {
        $handler = new Checkout_NIP_Handler();

        $fields = [
            'billing' => [
                'fields' => [
                    'billing_first_name' => ['label' => 'First name'],
                ],
            ],
        ];

        $result = $handler->add_customer_meta_fields($fields);

        $this->assertArrayHasKey('billing_nip', $result['billing']['fields']);
        $this->assertArrayHasKey('billing_company_data', $result['billing']['fields']);
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
        $order->shouldReceive('get_customer_id')->andReturn(123);

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

        // Assert customer user meta was updated
        $this->assertEquals('5252344078', get_user_meta(123, 'billing_nip', true));
        $this->assertEquals("ACME Sp. z o.o., ul. Marszałkowska 10, 00-001 Warszawa", get_user_meta(123, 'billing_company_data', true));

        unset($GLOBALS['_wp_user_meta'][123]);
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
        $order->shouldReceive('get_customer_id')->andReturn(0);

        $handler = new Checkout_NIP_Handler();
        $handler->save_nip_to_order($order, [
            'billing_nip'          => '5252344078',
            'billing_company_data' => 'ACME, ul. Testowa 2, 00-002 Warszawa',
        ]);

        $this->assertEquals('Existing Corp', $billing_company);
        $this->assertEquals('ul. Główna 1, ACME, ul. Testowa 2, 00-002 Warszawa', $billing_address);
    }
}
