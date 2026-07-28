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
        $this->assertEquals(1, Round_Resolver::resolve($set, $timestamp));
    }

    public function test_resolves_round_2() {
        $set = Mockery::mock(Set_Model::class);
        $set->shouldReceive('get_round_1_end_date')->andReturn('2026-08-01 23:59:59');
        $set->shouldReceive('get_round_2_end_date')->andReturn('2026-09-01 23:59:59');

        // Current time is after round 1 end but before round 2 end
        $timestamp = strtotime('2026-08-15 12:00:00');
        $this->assertEquals(2, Round_Resolver::resolve($set, $timestamp));
    }

    public function test_resolves_round_3() {
        $set = Mockery::mock(Set_Model::class);
        $set->shouldReceive('get_round_1_end_date')->andReturn('2026-08-01 23:59:59');
        $set->shouldReceive('get_round_2_end_date')->andReturn('2026-09-01 23:59:59');

        // Current time is after round 2 end
        $timestamp = strtotime('2026-10-01 12:00:00');
        $this->assertEquals(3, Round_Resolver::resolve($set, $timestamp));
    }

    public function test_fallback_to_round_1_when_no_dates_set() {
        $set = Mockery::mock(Set_Model::class);
        $set->shouldReceive('get_round_1_end_date')->andReturn('');
        $set->shouldReceive('get_round_2_end_date')->andReturn('');

        $timestamp = strtotime('2026-07-26 12:00:00');
        $this->assertEquals(1, Round_Resolver::resolve($set, $timestamp));
    }
}
