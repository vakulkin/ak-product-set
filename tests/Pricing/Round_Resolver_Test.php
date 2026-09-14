<?php
namespace AK_Set\Tests\Pricing;

use AK_Set\Tests\TestCase;
use AK_Set\Pricing\Round_Resolver;
use AK_Set\Models\Weekend_Model;
use Mockery;

class Round_Resolver_Test extends TestCase {
    
    public function test_resolves_weekend_round_1() {
        $w = Mockery::mock(Weekend_Model::class);
        $w->shouldReceive('get_current_round')->with(1000)->andReturn(1);
        $this->assertEquals(1, Round_Resolver::resolve_weekend_round($w, 1000));
    }

    public function test_resolves_weekend_round_2() {
        $w = Mockery::mock(Weekend_Model::class);
        $w->shouldReceive('get_current_round')->with(2000)->andReturn(2);
        $this->assertEquals(2, Round_Resolver::resolve_weekend_round($w, 2000));
    }

    public function test_resolves_weekend_round_3() {
        $w = Mockery::mock(Weekend_Model::class);
        $w->shouldReceive('get_current_round')->with(3000)->andReturn(3);
        $this->assertEquals(3, Round_Resolver::resolve_weekend_round($w, 3000));
    }

    public function test_resolves_weekend_round_returns_1_for_invalid_input() {
        $this->assertEquals(1, Round_Resolver::resolve_weekend_round(null));
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
