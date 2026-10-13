<?php

namespace AK_Set\Tests\Admin;

use AK_Set\Admin\Plugin_Settings_Page;
use AK_Set\Order\Order_Recalculator;
use AK_Set\Tests\TestCase;
use Brain\Monkey\Functions;

class Plugin_Settings_Page_Test extends TestCase {

    public function test_sanitize_cost_cleans_and_formats_float(): void {
        $page = new Plugin_Settings_Page();

        $this->assertSame(45.50, $page->sanitize_cost('45.50'));
        $this->assertSame(70.0,  $page->sanitize_cost('70,00'));
        $this->assertSame(0.0,   $page->sanitize_cost('-15.00'));
        $this->assertSame(0.0,   $page->sanitize_cost('abc'));
        $this->assertSame(50.25, $page->sanitize_cost(50.254));
    }

    public function test_register_settings_registers_tshirt_cost_and_ceidg_token(): void {
        $registered = [];

        Functions\when('register_setting')->alias(function($group, $option, $args) use (&$registered) {
            $registered[$option] = [
                'group' => $group,
                'args'  => $args,
            ];
        });

        $page = new Plugin_Settings_Page();
        $page->register_settings();

        $this->assertArrayHasKey('ak_set_tshirt_cost', $registered);
        $this->assertEquals(Plugin_Settings_Page::OPTION_GROUP, $registered['ak_set_tshirt_cost']['group']);
        $this->assertEquals(50.0, $registered['ak_set_tshirt_cost']['args']['default']);

        $this->assertArrayHasKey('ak_ceidg_api_token', $registered);
        $this->assertEquals(Plugin_Settings_Page::OPTION_GROUP, $registered['ak_ceidg_api_token']['group']);
    }

    public function test_render_page_outputs_settings_form(): void {
        Functions\stubs([
            'current_user_can' => true,
            'settings_fields'  => function($g) { echo "<input type='hidden' name='option_group' value='$g'>"; },
            'submit_button'    => function($t = 'Save') { echo "<button>$t</button>"; },
            'esc_html_e'       => function($v) { echo $v; },
            'esc_attr'         => function($v) { return (string) $v; },
        ]);

        update_option('ak_set_tshirt_cost', '55.00');

        $page = new Plugin_Settings_Page();
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString('Ustawienia AK Zestawy', $output);
        $this->assertStringContainsString('ak_set_tshirt_cost', $output);
        $this->assertStringContainsString('55.00', $output);
        $this->assertStringContainsString('ak_ceidg_api_token', $output);

        unset($GLOBALS['_wp_options']['ak_set_tshirt_cost']);
    }
}
