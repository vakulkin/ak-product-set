<?php
namespace AK_Set\Tests\Models;

use AK_Set\Tests\TestCase;
use AK_Set\Models\Set_Model;
use Brain\Monkey\Functions;

class Set_Model_Test extends TestCase {

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
}
