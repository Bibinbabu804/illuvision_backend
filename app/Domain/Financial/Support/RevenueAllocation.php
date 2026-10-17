<?php

namespace App\Domain\Financial\Support;

/**
 * RevenueAllocation
 *
 * Pure, deterministic revenue-allocation arithmetic for the Financial domain.
 *
 * ARCHITECTURAL NOTE (Phase 0.10):
 * This is an *extraction* of arithmetic that already existed inline in
 * `RevenueDistributionService::processBookingScreens()`. It is deliberately not
 * a redesign: the rounding order, the divisibility guard and the per-screen
 * divisor are reproduced verbatim, including the (economically imperfect but
 * historically authoritative) behaviour that each share is computed from the
 * ALREADY-ROUNDED per-screen gross rather than from the raw division.
 *
 * Rounding chain, unchanged:
 *   1. gross   = round(bookingAmount / totalScreens, 2)
 *   2. platform= round(gross * (illuvisionPct / 100), 2)
 *   3. provider= round(gross * (providerPct   / 100), 2)
 *
 * Because steps 2 and 3 round independently, the sum of shares may differ from
 * `gross` by up to 0.01 for pathological splits. That drift is a property of
 * the existing ledger and is intentionally preserved — it is a reconciliation
 * concern, not a calculation concern.
 *
 * No `float` is used for persistence. Snapshots are written to `decimal(12,2)`
 * / `decimal(14,2)` columns; this class only produces the pre-persistence value.
 */
final class RevenueAllocation
{
    /**
     * Per-screen gross revenue for a booking.
     *
     * Reproduces:
     *   $booking->final_amount > 0
     *       ? round($booking->final_amount / $totalScreens, 2)
     *       : round(($booking->subtotal ?? $booking->price ?? 0) / $totalScreens, 2)
     *
     * @param  float|int|string  $finalAmount  Authoritative booking final amount.
     * @param  float|int|string  $fallbackAmount  subtotal ?? price ?? 0.
     * @param  int  $totalScreens  Number of booking_screen rows.
     */
    public static function grossPerScreen($finalAmount, $fallbackAmount, int $totalScreens): float
    {
        $base = ((float) $finalAmount) > 0 ? (float) $finalAmount : (float) $fallbackAmount;

        return round($base / $totalScreens, 2);
    }

    /**
     * Split a per-screen gross amount into platform (Illuvision) and provider shares.
     *
     * Reproduces:
     *   $platformShare = round($grossRevenue * ($illuvisionPct / 100), 2);
     *   $providerShare = round($grossRevenue * ($providerPct   / 100), 2);
     *
     * @return array{gross_revenue: float, platform_share: float, provider_share: float}
     */
    public static function split(float $grossRevenue, float $illuvisionPercentage, float $screenProviderPercentage): array
    {
        return [
            'gross_revenue' => $grossRevenue,
            'platform_share' => round($grossRevenue * ($illuvisionPercentage / 100), 2),
            'provider_share' => round($grossRevenue * ($screenProviderPercentage / 100), 2),
        ];
    }

    /**
     * The 100% / 0% allocation used for Illuvision-owned screens.
     *
     * Reproduces the Illuvision branch of `processBookingScreens()`:
     *   platform_share = $grossRevenue, provider_share = 0.00,
     *   illuvision_percentage = 100.00, screen_provider_percentage = 0.00
     *
     * @return array{gross_revenue: float, platform_share: float, provider_share: float, illuvision_percentage: float, screen_provider_percentage: float}
     */
    public static function illuvisionOwned(float $grossRevenue): array
    {
        return [
            'gross_revenue' => $grossRevenue,
            'platform_share' => $grossRevenue,
            'provider_share' => 0.00,
            'illuvision_percentage' => 100.00,
            'screen_provider_percentage' => 0.00,
        ];
    }
}
