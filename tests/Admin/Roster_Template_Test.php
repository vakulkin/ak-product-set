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
    }
}
