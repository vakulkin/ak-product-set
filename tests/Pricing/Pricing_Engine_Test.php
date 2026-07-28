<?php
namespace AK_Set\Tests\Pricing;

use AK_Set\Tests\TestCase;
use AK_Set\Pricing\Pricing_Engine;
use AK_Set\Models\Set_Model;
use AK_Set\Models\Weekend_Model;
use Mockery;
use Brain\Monkey\Functions;

class Pricing_Engine_Test extends TestCase {

    // -------------------------------------------------------------------------
    // resolve_group_tier
    // -------------------------------------------------------------------------

    public function test_resolve_group_tier_ind() {
        $this->assertEquals('ind', Pricing_Engine::resolve_group_tier(1));
        $this->assertEquals('ind', Pricing_Engine::resolve_group_tier(4));
    }

    public function test_resolve_group_tier_g5() {
        $this->assertEquals('g5', Pricing_Engine::resolve_group_tier(5));
        $this->assertEquals('g5', Pricing_Engine::resolve_group_tier(9));
    }

    public function test_resolve_group_tier_g10() {
        $this->assertEquals('g10', Pricing_Engine::resolve_group_tier(10));
        $this->assertEquals('g10', Pricing_Engine::resolve_group_tier(50));
    }

    public function test_resolve_group_tier_zero_and_negative_fall_back_to_ind() {
        $this->assertEquals('ind', Pricing_Engine::resolve_group_tier(0));
        $this->assertEquals('ind', Pricing_Engine::resolve_group_tier(-10));
    }

    // -------------------------------------------------------------------------
    // calculate – invalid set / IDOR / package size guards
    // -------------------------------------------------------------------------

    public function test_calculate_returns_invalid_when_set_does_not_exist() {
        Functions\when('get_post')->justReturn(null);

        $result = Pricing_Engine::calculate(999, [1, 2], 1);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('istnieje', $result['error']);
    }

    public function test_calculate_rejects_disallowed_weekends_via_idor_intersection() {
        // Simulate set that allows only weekend IDs 10 and 11
        $allowedWeekend1 = Mockery::mock(Weekend_Model::class);
        $allowedWeekend1->shouldReceive('get_id')->andReturn(10);
        $allowedWeekend2 = Mockery::mock(Weekend_Model::class);
        $allowedWeekend2->shouldReceive('get_id')->andReturn(11);

        $fakePost = new \stdClass();
        $fakePost->ID = 1;
        $fakePost->post_type = 'ak_set';
        $fakePost->post_title = 'Test Set';

        Functions\when('get_post')->justReturn($fakePost);
        Functions\when('get_field')->justReturn([]);

        // Passing weekend IDs 10, 99 — 99 should be stripped
        // This tests the array_intersect() IDOR protection.
        // Since get_weekend_products returns empty array (via get_field mock → []),
        // the intersected result will be empty → package_size = 0 → invalid.
        $result = Pricing_Engine::calculate(1, [10, 99], 1);
        $this->assertFalse($result['valid']);
    }

    public function test_calculate_returns_invalid_for_zero_weekends() {
        $fakePost = new \stdClass();
        $fakePost->ID = 1;
        $fakePost->post_type = 'ak_set';
        $fakePost->post_title = 'Test Set';

        Functions\when('get_post')->justReturn($fakePost);
        Functions\when('get_field')->justReturn([]);

        $result = Pricing_Engine::calculate(1, [], 1);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('weekendów', $result['error']);
    }

    public function test_calculate_returns_invalid_when_price_is_zero_or_not_set() {
        $fakePost = new \stdClass();
        $fakePost->ID = 1;
        $fakePost->post_type = 'ak_set';
        $fakePost->post_title = 'Test Set';

        $weekend = Mockery::mock(Weekend_Model::class);
        $weekend->shouldReceive('get_id')->andReturn(10);
        $weekend->shouldReceive('get_wc_product')->andReturn(new \stdClass());

        Functions\when('get_post')->justReturn($fakePost);
        Functions\when('wc_get_product')->justReturn(new \stdClass());
        Functions\when('get_field')->alias(function($key) use ($weekend) {
            if ($key === 'set_products') return [10];
            return '';
        });
        Functions\when('get_post_meta')->justReturn(''); // No price metadata in postmeta -> 0.0

        $result = Pricing_Engine::calculate(1, [10], 1);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('wynosi 0 zł', $result['error']);
    }

    public function test_calculate_returns_invalid_when_price_is_negative() {
        $fakePost = new \stdClass();
        $fakePost->ID = 1;
        $fakePost->post_type = 'ak_set';

        $weekend = Mockery::mock(Weekend_Model::class);
        $weekend->shouldReceive('get_id')->andReturn(10);
        $weekend->shouldReceive('get_wc_product')->andReturn(new \stdClass());

        Functions\when('get_post')->justReturn($fakePost);
        Functions\when('wc_get_product')->justReturn(new \stdClass());
        Functions\when('get_field')->alias(function($key) {
            if ($key === 'set_products') return [10];
            return '';
        });
        Functions\when('get_post_meta')->justReturn('-150.00'); // Negative price in meta

        $result = Pricing_Engine::calculate(1, [10], 1);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('wynosi 0 zł', $result['error']);
    }

    // -------------------------------------------------------------------------
    // get_max_headcount_limit
    // -------------------------------------------------------------------------

    public function test_get_max_headcount_limit_returns_null_for_empty_selection() {
        $result = Pricing_Engine::get_max_headcount_limit([]);
        $this->assertNull($result);
    }
}
