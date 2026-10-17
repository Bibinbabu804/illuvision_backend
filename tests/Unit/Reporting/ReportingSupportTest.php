<?php

namespace Tests\Unit\Reporting;

use App\Application\Reporting\Support\DurationLabelFormatter;
use App\Application\Reporting\Support\GrowthRate;
use App\Application\Reporting\Support\ReportPeriod;
use App\Support\DurationFormatter;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the stateless helpers extracted from the legacy ReportService.
 *
 * These pin the exact edge-case semantics (loose zero comparison, period default
 * asymmetry, delegation) that the dashboard KPI payload depends on.
 */
class ReportingSupportTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | GrowthRate
    |--------------------------------------------------------------------------
    */

    public function test_it_returns_zero_when_both_periods_are_zero(): void
    {
        $this->assertSame(0.0, GrowthRate::between(0, 0));
    }

    public function test_it_returns_one_hundred_when_the_baseline_is_zero(): void
    {
        $this->assertSame(100.0, GrowthRate::between(0, 500));
    }

    public function test_it_computes_a_plain_percentage_change(): void
    {
        $this->assertSame(50.0, GrowthRate::between(100, 150));
        $this->assertSame(-50.0, GrowthRate::between(200, 100));
    }

    public function test_it_rounds_to_one_decimal_place(): void
    {
        // 1/3 -> 33.333... -> 33.3
        $this->assertSame(33.3, GrowthRate::between(3, 4));

        // 2/3 -> 66.666... -> 66.7
        $this->assertSame(66.7, GrowthRate::between(3, 5));
    }

    public function test_it_returns_a_negative_hundred_for_a_total_collapse(): void
    {
        $this->assertSame(-100.0, GrowthRate::between(100, 0));
    }

    /**
     * The loose `== 0` comparison means any falsy baseline (not just 0.0) is
     * treated as a zero baseline. This is preserved legacy behaviour.
     */
    public function test_a_tiny_nonzero_baseline_is_still_treated_as_nonzero(): void
    {
        // ((1 - 0.0001) / 0.0001) * 100 = 999900
        $this->assertSame(999900.0, GrowthRate::between(0.0001, 1));
    }

    public function test_it_always_returns_a_float(): void
    {
        // The 0 and 100 branches return int literals from a float return type.
        // json_encode would render 0 and 100 without this coercion.
        $this->assertIsFloat(GrowthRate::between(0, 0));
        $this->assertIsFloat(GrowthRate::between(0, 10));
        $this->assertIsFloat(GrowthRate::between(10, 20));
    }

    /*
    |--------------------------------------------------------------------------
    | ReportPeriod
    |--------------------------------------------------------------------------
    */

    public function test_it_defaults_to_the_current_calendar_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:30:00'));

        $period = ReportPeriod::resolve([]);

        $this->assertSame('2026-08-01', $period['date_from']);
        $this->assertSame('2026-08-31', $period['date_to']);
    }

    public function test_it_defaults_across_a_month_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-05 00:00:00'));

        $period = ReportPeriod::resolve([]);

        $this->assertSame('2026-02-01', $period['date_from']);
        $this->assertSame('2026-02-28', $period['date_to']);
    }

    public function test_it_passes_supplied_values_through_untouched(): void
    {
        // The controller formats date_from as "Y-m-d" but date_to as a full
        // end-of-day timestamp. ReportPeriod must not normalise either side.
        $period = ReportPeriod::resolve([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31 23:59:59',
        ]);

        $this->assertSame('2026-07-01', $period['date_from']);
        $this->assertSame('2026-07-31 23:59:59', $period['date_to']);
    }

    public function test_it_returns_only_the_two_period_keys(): void
    {
        $period = ReportPeriod::resolve(['city_id' => 7, 'status' => 'approved']);

        $this->assertSame(['date_from', 'date_to'], array_keys($period));
    }

    public function test_it_honours_a_supplied_start_with_a_default_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:30:00'));

        $period = ReportPeriod::resolve(['date_from' => '2026-01-01']);

        $this->assertSame('2026-01-01', $period['date_from']);
        $this->assertSame('2026-08-31', $period['date_to']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | DurationLabelFormatter
    |--------------------------------------------------------------------------
    */

    /**
     * The formatter is a pure delegation, so it must agree with the canonical
     * implementation for every input.
     */
    #[DataProvider('minuteInputs')]
    public function test_it_delegates_to_the_canonical_duration_formatter(int $minutes): void
    {
        $this->assertSame(
            DurationFormatter::formatMinutes($minutes),
            DurationLabelFormatter::formatMinutes($minutes),
        );
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function minuteInputs(): array
    {
        return [
            'zero' => [0],
            'under an hour' => [45],
            'exactly one hour' => [60],
            'hours and minutes' => [135],
            'exactly one day' => [1440],
            'multi day' => [4320],
            'odd value' => [7],
        ];
    }

    public function test_it_formats_a_sub_minute_span_as_zero(): void
    {
        $this->assertSame(
            DurationFormatter::formatMinutes(0),
            DurationLabelFormatter::formatMinutes(0),
        );
    }
}
