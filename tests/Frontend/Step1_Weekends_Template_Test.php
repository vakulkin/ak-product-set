<?php

namespace AK_Set\Tests\Frontend;

use AK_Set\Tests\TestCase;
use AK_Set\Models\Weekend_Model;
use Brain\Monkey\Functions;
use Mockery;

class Step1_Weekends_Template_Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\stubs([
            'esc_attr'        => function ($v) { return (string)$v; },
            'esc_html_e'      => function ($v) { echo $v; },
            'checked'         => function ($c) { if ($c) echo 'checked="checked"'; },
            'disabled'        => function ($d) { if ($d) echo 'disabled="disabled"'; },
            'wp_kses_post'    => function ($v) { return $v; },
            'date_i18n'       => function ($format, $timestamp) { return date($format, $timestamp); },
        ]);
    }

    public function test_past_event_hides_round_badge_and_available_places(): void
    {
        $pastWeekend = Mockery::mock(Weekend_Model::class);
        $pastWeekend->shouldReceive('get_id')->andReturn(101);
        $pastWeekend->shouldReceive('is_expired')->andReturn(true);
        $pastWeekend->shouldReceive('managing_stock')->andReturn(true);
        $pastWeekend->shouldReceive('get_stock_quantity')->andReturn(10);
        $pastWeekend->shouldReceive('get_event_start_datetime')->andReturn('2023-01-01 10:00:00');
        $pastWeekend->shouldReceive('get_event_end_datetime')->andReturn('2023-01-02 18:00:00');
        $pastWeekend->shouldReceive('get_recruitment_start_datetime')->andReturn('');
        $pastWeekend->shouldReceive('get_recruitment_end_datetime')->andReturn('');
        $pastWeekend->shouldReceive('get_current_round')->andReturn(1);
        $pastWeekend->shouldReceive('get_round_1_end_datetime')->andReturn('');
        $pastWeekend->shouldReceive('get_round_2_end_datetime')->andReturn('');
        $pastWeekend->shouldReceive('get_round_3_end_datetime')->andReturn('');
        $pastWeekend->shouldReceive('get_event_location')->andReturn('Warszawa');
        $pastWeekend->shouldReceive('get_image_url')->andReturn('http://example.com/img.jpg');
        $pastWeekend->shouldReceive('get_description')->andReturn('Past event description');
        $pastWeekend->shouldReceive('get_title')->andReturn('Weekend 1');

        $weekends = [$pastWeekend];
        $pre_selected_raw = [];

        $template_path = dirname(__DIR__, 2) . '/templates/frontend/step-1-weekends.php';
        ob_start();
        include $template_path;
        $html = ob_get_clean();

        // 1. Must NOT contain round badge
        $this->assertStringNotContainsString('Runda 1', $html);
        $this->assertStringNotContainsString('Early Bird', $html);

        // 2. Must NOT contain available places badge
        $this->assertStringNotContainsString('miejsc dostępnych', $html);
        $this->assertStringNotContainsString('Miejsca dostępne', $html);
        $this->assertStringNotContainsString('Zostało', $html);

        // 3. Must display unified expired badge "Rekrutacja zakończona"
        $this->assertStringContainsString('Rekrutacja zakończona', $html);

        // 4. Card must be marked disabled
        $this->assertStringContainsString('ak-weekend-card disabled', $html);
        $this->assertStringContainsString('disabled="disabled"', $html);
    }

    public function test_active_event_shows_round_badge_and_available_places(): void
    {
        $activeWeekend = Mockery::mock(Weekend_Model::class);
        $activeWeekend->shouldReceive('get_id')->andReturn(202);
        $activeWeekend->shouldReceive('is_expired')->andReturn(false);
        $activeWeekend->shouldReceive('managing_stock')->andReturn(true);
        $activeWeekend->shouldReceive('get_stock_quantity')->andReturn(8);
        $activeWeekend->shouldReceive('get_event_start_datetime')->andReturn('2028-06-01 10:00:00');
        $activeWeekend->shouldReceive('get_event_end_datetime')->andReturn('2028-06-02 18:00:00');
        $activeWeekend->shouldReceive('get_recruitment_start_datetime')->andReturn('');
        $activeWeekend->shouldReceive('get_recruitment_end_datetime')->andReturn('');
        $activeWeekend->shouldReceive('get_current_round')->andReturn(1);
        $activeWeekend->shouldReceive('get_round_1_end_datetime')->andReturn('2028-05-01 23:59:59');
        $activeWeekend->shouldReceive('get_round_2_end_datetime')->andReturn('');
        $activeWeekend->shouldReceive('get_round_3_end_datetime')->andReturn('');
        $activeWeekend->shouldReceive('get_event_location')->andReturn('Kraków');
        $activeWeekend->shouldReceive('get_image_url')->andReturn('http://example.com/img2.jpg');
        $activeWeekend->shouldReceive('get_description')->andReturn('Active event description');
        $activeWeekend->shouldReceive('get_title')->andReturn('Weekend 2');

        $weekends = [$activeWeekend];
        $pre_selected_raw = [];

        $template_path = dirname(__DIR__, 2) . '/templates/frontend/step-1-weekends.php';
        ob_start();
        include $template_path;
        $html = ob_get_clean();

        // 1. Must show round badge
        $this->assertStringContainsString('Runda 1', $html);

        // 2. Must show available places badge
        $this->assertStringContainsString('8 miejsc dostępnych', $html);

        // 3. Must not show "Wydarzenie zakończone"
        $this->assertStringNotContainsString('Wydarzenie zakończone', $html);
        $this->assertStringNotContainsString('Rekrutacja zakończona', $html);

        // 4. Card must not be disabled
        $this->assertStringNotContainsString('ak-weekend-card disabled', $html);
    }
}
