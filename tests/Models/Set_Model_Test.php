<?php
namespace AK_Set\Tests\Models;

use AK_Set\Tests\TestCase;
use AK_Set\Models\Set_Model;
use Brain\Monkey\Functions;

class Set_Model_Test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Functions\when('get_field')->alias(function($field, $post_id) {
            return get_post_meta($post_id, $field, true);
        });
    }

    public function test_exists_returns_true_for_ak_set_cpt() {
        $fakePost = new \stdClass();
        $fakePost->ID = 10;
        $fakePost->post_type = 'ak_set';
        $fakePost->post_title = 'Winter Set';

        Functions\when('get_post')->justReturn($fakePost);

        $set = new Set_Model(10);
        $this->assertTrue($set->exists());
        $this->assertEquals(10, $set->get_id());
        $this->assertEquals('Winter Set', $set->get_title());
    }

    public function test_exists_returns_false_for_non_existent_post() {
        Functions\when('get_post')->justReturn(null);

        $set = new Set_Model(999);
        $this->assertFalse($set->exists());
    }

    public function test_get_price_by_matrix_fetches_w_suffix_postmeta() {
        $fakePost = new \stdClass();
        $fakePost->ID = 10;
        $fakePost->post_type = 'ak_set';

        Functions\when('get_post')->justReturn($fakePost);
        Functions\when('get_post_meta')->alias(function($post_id, $key, $single) {
            if ($key === 'price_2w_round1_ind') {
                return '199.99';
            }
            return '';
        });

        $set = new Set_Model(10);
        $price = $set->get_price_by_matrix(2, 1, 'ind');
        $this->assertEquals(199.99, $price);
    }

    public function test_get_price_by_matrix_falls_back_to_round_1_when_higher_round_empty() {
        $fakePost = new \stdClass();
        $fakePost->ID = 10;
        $fakePost->post_type = 'ak_set';

        Functions\when('get_post')->justReturn($fakePost);
        Functions\when('get_post_meta')->alias(function($post_id, $key, $single) {
            // Round 2 is empty, Round 1 has 150.00
            if ($key === 'price_3w_round1_g5') {
                return '150.00';
            }
            return '';
        });

        $set = new Set_Model(10);
        // Request Round 2, should fall back to Round 1
        $price = $set->get_price_by_matrix(3, 2, 'g5');
        $this->assertEquals(150.00, $price);
    }

    public function test_default_unit_is_weekend_and_formats_polish_declensions() {
        $fakePost = new \stdClass();
        $fakePost->ID = 10;
        $fakePost->post_type = 'ak_set';
        Functions\when('get_post')->justReturn($fakePost);
        Functions\when('get_post_meta')->alias(function($post_id, $key, $single) {
            return ''; // No custom unit set
        });

        $set = new Set_Model(10);
        $units = $set->get_unit_names();

        $this->assertEquals('weekend', $units['singular']);
        $this->assertEquals('weekendy', $units['plural_few']);
        $this->assertEquals('weekendów', $units['plural_many']);

        $this->assertEquals('1 weekend', $set->format_package_size(1));
        $this->assertEquals('2 weekendy', $set->format_package_size(2));
        $this->assertEquals('3 weekendy', $set->format_package_size(3));
        $this->assertEquals('4 weekendy', $set->format_package_size(4));
        $this->assertEquals('5 weekendów', $set->format_package_size(5));
        $this->assertEquals('10 weekendów', $set->format_package_size(10));
        $this->assertEquals('12 weekendów', $set->format_package_size(12));
        $this->assertEquals('22 weekendy', $set->format_package_size(22));
        $this->assertEquals('25 weekendów', $set->format_package_size(25));
    }

    public function test_preset_unit_zajecia_formats_correctly() {
        $fakePost = new \stdClass();
        $fakePost->ID = 10;
        $fakePost->post_type = 'ak_set';
        Functions\when('get_post')->justReturn($fakePost);
        Functions\when('get_post_meta')->alias(function($post_id, $key, $single) {
            if ($key === 'set_unit_type') {
                return 'zajecia';
            }
            return '';
        });

        $set = new Set_Model(10);
        $this->assertEquals('1 zajęcia', $set->format_package_size(1));
        $this->assertEquals('2 zajęcia', $set->format_package_size(2));
        $this->assertEquals('5 zajęć', $set->format_package_size(5));
    }

    public function test_custom_unit_formats_correctly() {
        $fakePost = new \stdClass();
        $fakePost->ID = 10;
        $fakePost->post_type = 'ak_set';
        Functions\when('get_post')->justReturn($fakePost);
        Functions\when('get_post_meta')->alias(function($post_id, $key, $single) {
            return match ($key) {
                'set_unit_type'        => 'custom',
                'set_unit_singular'    => 'moduł',
                'set_unit_plural_few'  => 'moduły',
                'set_unit_plural_many' => 'modułów',
                default                => '',
            };
        });

        $set = new Set_Model(10);
        $units = $set->get_unit_names();
        $this->assertEquals('moduł', $units['singular']);
        $this->assertEquals('moduły', $units['plural_few']);
        $this->assertEquals('modułów', $units['plural_many']);

        $this->assertEquals('1 moduł', $set->format_package_size(1));
        $this->assertEquals('3 moduły', $set->format_package_size(3));
        $this->assertEquals('7 modułów', $set->format_package_size(7));
    }
}
