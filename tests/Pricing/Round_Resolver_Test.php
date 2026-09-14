<?php
namespace AK_Set\Tests\Pricing;

use AK_Set\Tests\TestCase;
use AK_Set\Pricing\Round_Resolver;
use AK_Set\Models\Set_Model;
use Mockery;

class Round_Resolver_Test extends TestCase {
    
    public function test_resolves_round_1() {
        $set = Mockery::mock(Set_Model::class);
        $set->shouldReceive('get_round_1_end_date')->andReturn('2026-08-01 23:59:59');
        $set->shouldReceive('get_round_2_end_date')->andReturn('2026-09-01 23:59:59');

        // Current time is before round 1 end
        $timestamp = strtotime('2026-07-26 12:00:00');
        $this->assertEquals(1, Round_Resolver::resolve_round($set, $timestamp));
    }

    public function test_resolves_round_2() {
        $set = Mockery::mock(Set_Model::class);
        $set->shouldReceive('get_round_1_end_date')->andReturn('2026-08-01 23:59:59');
        $set->shouldReceive('get_round_2_end_date')->andReturn('2026-09-01 23:59:59');

        // Current time is after round 1 end but before round 2 end
        $timestamp = strtotime('2026-08-15 12:00:00');
        $this->assertEquals(2, Round_Resolver::resolve_round($set, $timestamp));
    }

    public function test_resolves_round_3() {
        $set = Mockery::mock(Set_Model::class);
        $set->shouldReceive('get_round_1_end_date')->andReturn('2026-08-01 23:59:59');
        $set->shouldReceive('get_round_2_end_date')->andReturn('2026-09-01 23:59:59');

        // Current time is after round 2 end
        $timestamp = strtotime('2026-10-01 12:00:00');
        $this->assertEquals(3, Round_Resolver::resolve_round($set, $timestamp));
    }

    public function test_fallback_to_round_1_when_no_dates_set() {
        $set = Mockery::mock(Set_Model::class);
        $set->shouldReceive('get_round_1_end_date')->andReturn('');
        $set->shouldReceive('get_round_2_end_date')->andReturn('');

        $timestamp = strtotime('2026-07-26 12:00:00');
        $this->assertEquals(1, Round_Resolver::resolve_round($set, $timestamp));
    }

    public function test_resolve_weekend_round_from_weekend_model() {
        $w = Mockery::mock(\AK_Set\Models\Weekend_Model::class);
        $w->shouldReceive('get_current_round')->with(123456)->andReturn(2);

        $this->assertEquals(2, Round_Resolver::resolve_weekend_round($w, 123456));
    }

    public function test_resolve_package_round_variant_a_picks_max_round() {
        // Setup Brain\Monkey functions for get_field
        \Brain\Monkey\Functions\when('wc_get_product')->justReturn(new \stdClass());
        \Brain\Monkey\Functions\when('get_field')->alias(function($key, $id) {
            if ($id === 101) {
                // Weekend 101: Round 1 ends in future -> currently Round 1
                if ($key === 'ak_round_1_end_datetime') return '2026-10-01 23:59:59';
                if ($key === 'ak_round_2_end_datetime') return '2026-11-01 23:59:59';
            }
            if ($id === 102) {
                // Weekend 102: Round 1 expired in past, Round 2 in future -> currently Round 2
                if ($key === 'ak_round_1_end_datetime') return '2026-08-01 23:59:59';
                if ($key === 'ak_round_2_end_datetime') return '2026-10-01 23:59:59';
            }
            if ($id === 103) {
                // Weekend 103: Round 2 expired -> currently Round 3
                if ($key === 'ak_round_1_end_datetime') return '2026-07-01 23:59:59';
                if ($key === 'ak_round_2_end_datetime') return '2026-08-01 23:59:59';
            }
            return '';
        });

        $testTime = strtotime('2026-08-15 12:00:00');

        // Only Weekend 101 -> Round 1
        $this->assertEquals(1, Round_Resolver::resolve_package_round([101], $testTime));

        // Only Weekend 102 -> Round 2
        $this->assertEquals(2, Round_Resolver::resolve_package_round([102], $testTime));

        // Mixed: Weekend 101 (R1) + Weekend 102 (R2) -> Variant A picks max (Round 2)
        $this->assertEquals(2, Round_Resolver::resolve_package_round([101, 102], $testTime));

        // Mixed: Weekend 101 (R1) + Weekend 103 (R3) -> Variant A picks max (Round 3)
        $this->assertEquals(3, Round_Resolver::resolve_package_round([101, 103], $testTime));
    }
}
