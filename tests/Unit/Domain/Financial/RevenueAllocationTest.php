<?php

namespace Tests\Unit\Domain\Financial;

use App\Domain\Financial\Support\RevenueAllocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0.10 — Revenue allocation arithmetic regression.
 *
 * `RevenueAllocation` is a pure extraction of arithmetic that previously lived
 * inline in `RevenueDistributionService::processBookingScreens()`. These tests
 * pin the *legacy* rounding order, including the known property that each share
 * is computed from the already-rounded per-screen gross.
 */
class RevenueAllocationTest extends TestCase
{
    #[DataProvider('grossPerScreenProvider')]
    public function test_gross_per_screen_prefers_final_amount(
        $finalAmount,
        $fallbackAmount,
        int $totalScreens,
        float $expected
    ): void {
        $this->assertSame(
            $expected,
            RevenueAllocation::grossPerScreen($finalAmount, $fallbackAmount, $totalScreens)
        );
    }

    public static function grossPerScreenProvider(): array
    {
        return [
            'final amount wins' => [1000.00, 999.00, 4, 250.0],
            'zero final amount falls back' => [0, 800.00, 4, 200.0],
            'negative final amount falls back' => [-50, 400.00, 2, 200.0],
            'rounds to two decimals' => [1000.00, 0, 3, 333.33],
            'single screen is identity' => [123.45, 0, 1, 123.45],
            'string amounts' => ['1000.00', '0', 8, 125.0],
        ];
    }

    #[DataProvider('splitProvider')]
    public function test_split_rounds_each_share_from_rounded_gross(
        float $gross,
        float $platformPct,
        float $providerPct,
        float $expectedPlatform,
        float $expectedProvider
    ): void {
        $result = RevenueAllocation::split($gross, $platformPct, $providerPct);

        $this->assertSame($gross, $result['gross_revenue']);
        $this->assertSame($expectedPlatform, $result['platform_share']);
        $this->assertSame($expectedProvider, $result['provider_share']);
    }

    public static function splitProvider(): array
    {
        return [
            'default 10/90' => [250.00, 10.00, 90.00, 25.00, 225.00],
            '50/50' => [333.33, 50.00, 50.00, 166.67, 166.67],
            '100/0 illuvision direct' => [123.45, 100.00, 0.00, 123.45, 0.00],
            '0/100 external provider' => [123.45, 0.00, 100.00, 0.00, 123.45],
            // 999.99 * 0.7 = 699.993 -> 699.99 (not 700.00): the gross is the
            // rounding base, so near-boundary values are pulled in.
            '70/30' => [999.99, 70.00, 30.00, 699.99, 300.0],
        ];
    }

    public function test_rounding_drift_is_preserved_not_corrected(): void
    {
        // Independent rounding of each share can leave a one-paisa gap against
        // the gross. This is historical ledger behaviour and must survive the
        // extraction as a reconciliation concern.
        $gross = RevenueAllocation::grossPerScreen(100.00, 0, 3); // 33.33
        $split = RevenueAllocation::split($gross, 33.33, 33.33);

        $this->assertSame(33.33, $split['gross_revenue']);
        $this->assertNotSame(
            $split['gross_revenue'],
            round($split['platform_share'] + $split['provider_share'], 2)
        );
    }

    public function test_illuvision_owned_allocation_is_100_0(): void
    {
        $result = RevenueAllocation::illuvisionOwned(250.00);

        $this->assertSame(250.00, $result['gross_revenue']);
        $this->assertSame(250.00, $result['platform_share']);
        $this->assertSame(0.00, $result['provider_share']);
        $this->assertSame(100.00, $result['illuvision_percentage']);
        $this->assertSame(0.00, $result['screen_provider_percentage']);
    }
}
