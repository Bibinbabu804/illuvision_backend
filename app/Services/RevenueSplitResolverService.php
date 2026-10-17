<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPricingBreakdown;
use App\Models\BookingScreen;
use App\Models\PricingDurationRule;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenDurationSplitOverride;
use DomainException;
use InvalidArgumentException;

/**
 * Single authoritative service responsible for resolving the effective revenue split
 * for any asset/booking context in Illuvision.
 *
 * Precedence Hierarchy:
 * 1. Direct Illuvision Ownership (100% Illuvision / 0% Provider)
 * 2. Duration-Specific Screen Split Override (Active match for Screen + Plan + DurationRule)
 * 3. Screen-Level Static Split Override (screens.illuvision_percentage)
 * 4. Provider-Level Default Split (screen_providers.illuvision_percentage)
 * 5. System Fallback Default (10.00% Illuvision / 90.00% Provider)
 */
class RevenueSplitResolverService
{
    public const SOURCE_DIRECT_ILLUVISION = 'DIRECT_ILLUVISION';
    public const SOURCE_DURATION_OVERRIDE = 'DURATION_OVERRIDE';
    public const SOURCE_SCREEN_OVERRIDE = 'SCREEN_OVERRIDE';
    public const SOURCE_PROVIDER_SPLIT = 'PROVIDER_SPLIT';
    public const SOURCE_SYSTEM_DEFAULT = 'SYSTEM_DEFAULT';

    public const DEFAULT_ILLUVISION_PERCENTAGE = 10.00;
    public const DEFAULT_SCREEN_PROVIDER_PERCENTAGE = 90.00;

    /**
     * Resolve the effective revenue split for a screen, pricing plan, and duration rule.
     *
     * @param Screen $screen
     * @param PricingPlan|null $plan
     * @param PricingDurationRule|null $durationRule
     * @return array{
     *   illuvision_percentage: float,
     *   screen_provider_percentage: float,
     *   resolution_source: string,
     *   screen_id: int,
     *   pricing_plan_id: int|null,
     *   pricing_duration_rule_id: int|null,
     *   is_override_applied: bool
     * }
     * @throws DomainException If persisted split configuration values are invalid or do not sum to 100%
     */
    public function resolveEffectiveSplit(
        Screen $screen,
        ?PricingPlan $plan = null,
        ?PricingDurationRule $durationRule = null
    ): array {
        // 1. Direct Illuvision Ownership takes absolute precedence
        if ($screen->isOwnedByIlluvision()) {
            return [
                'illuvision_percentage' => 100.00,
                'screen_provider_percentage' => 0.00,
                'resolution_source' => self::SOURCE_DIRECT_ILLUVISION,
                'screen_id' => $screen->id,
                'pricing_plan_id' => $plan?->id ?? $screen->pricing_plan_id,
                'pricing_duration_rule_id' => null,
                'is_override_applied' => false,
            ];
        }

        // Resolve plan if not explicitly passed but screen has pricing_plan_id
        if ($plan === null && $screen->pricing_plan_id) {
            $plan = $screen->relationLoaded('pricingPlan')
                ? $screen->pricingPlan
                : PricingPlan::find($screen->pricing_plan_id);
        }

        // 2. Duration-Specific Screen Split Override
        if ($durationRule !== null && $plan !== null) {
            // Guard against cross-plan duration rule mismatch
            if ((int) $durationRule->pricing_plan_id === (int) $plan->id) {
                $override = ScreenDurationSplitOverride::where('screen_id', $screen->id)
                    ->where('pricing_plan_id', $plan->id)
                    ->where('pricing_duration_rule_id', $durationRule->id)
                    ->where('is_active', true)
                    ->first();

                if ($override) {
                    $this->validatePercentages(
                        (float) $override->illuvision_percentage,
                        (float) $override->screen_provider_percentage,
                        "Duration override ID {$override->id} for Screen {$screen->id}"
                    );

                    return [
                        'illuvision_percentage' => (float) $override->illuvision_percentage,
                        'screen_provider_percentage' => (float) $override->screen_provider_percentage,
                        'resolution_source' => self::SOURCE_DURATION_OVERRIDE,
                        'screen_id' => $screen->id,
                        'pricing_plan_id' => $plan->id,
                        'pricing_duration_rule_id' => $durationRule->id,
                        'is_override_applied' => true,
                    ];
                }
            }
        }

        // 3. Screen-Level Static Split Override
        if ($screen->illuvision_percentage !== null && $screen->screen_provider_percentage !== null) {
            $this->validatePercentages(
                (float) $screen->illuvision_percentage,
                (float) $screen->screen_provider_percentage,
                "Screen ID {$screen->id} static split"
            );

            return [
                'illuvision_percentage' => (float) $screen->illuvision_percentage,
                'screen_provider_percentage' => (float) $screen->screen_provider_percentage,
                'resolution_source' => self::SOURCE_SCREEN_OVERRIDE,
                'screen_id' => $screen->id,
                'pricing_plan_id' => $plan?->id ?? $screen->pricing_plan_id,
                'pricing_duration_rule_id' => null,
                'is_override_applied' => true,
            ];
        }

        // 4. Provider-Level Default Split
        $provider = $screen->relationLoaded('screenProvider')
            ? $screen->screenProvider
            : ($screen->screen_provider_id ? $screen->screenProvider()->first() : null);

        if ($provider && $provider->illuvision_percentage !== null && $provider->screen_provider_percentage !== null) {
            $this->validatePercentages(
                (float) $provider->illuvision_percentage,
                (float) $provider->screen_provider_percentage,
                "Provider ID {$provider->id} default split"
            );

            return [
                'illuvision_percentage' => (float) $provider->illuvision_percentage,
                'screen_provider_percentage' => (float) $provider->screen_provider_percentage,
                'resolution_source' => self::SOURCE_PROVIDER_SPLIT,
                'screen_id' => $screen->id,
                'pricing_plan_id' => $plan?->id ?? $screen->pricing_plan_id,
                'pricing_duration_rule_id' => null,
                'is_override_applied' => false,
            ];
        }

        // 5. System Fallback Default (10% Illuvision / 90% Provider)
        return [
            'illuvision_percentage' => self::DEFAULT_ILLUVISION_PERCENTAGE,
            'screen_provider_percentage' => self::DEFAULT_SCREEN_PROVIDER_PERCENTAGE,
            'resolution_source' => self::SOURCE_SYSTEM_DEFAULT,
            'screen_id' => $screen->id,
            'pricing_plan_id' => $plan?->id ?? $screen->pricing_plan_id,
            'pricing_duration_rule_id' => null,
            'is_override_applied' => false,
        ];
    }

    /**
     * Resolve effective split for a BookingScreen within a Booking context.
     *
     * @param BookingScreen $bookingScreen
     * @param Booking|null $booking
     * @return array
     */
    public function resolveForBookingScreen(BookingScreen $bookingScreen, ?Booking $booking = null): array
    {
        $screen = $bookingScreen->relationLoaded('screen')
            ? $bookingScreen->screen
            : Screen::with(['screenProvider', 'pricingPlan'])->find($bookingScreen->screen_id);

        if (!$screen) {
            return [
                'illuvision_percentage' => self::DEFAULT_ILLUVISION_PERCENTAGE,
                'screen_provider_percentage' => self::DEFAULT_SCREEN_PROVIDER_PERCENTAGE,
                'resolution_source' => self::SOURCE_SYSTEM_DEFAULT,
                'screen_id' => $bookingScreen->screen_id,
                'pricing_plan_id' => null,
                'pricing_duration_rule_id' => null,
                'is_override_applied' => false,
            ];
        }

        $durationRule = null;
        $plan = $screen->relationLoaded('pricingPlan') ? $screen->pricingPlan : $screen->pricingPlan()->first();

        // Attempt to find duration rule from booking pricing breakdowns if booking is available
        if ($booking) {
            $durationRule = $this->findDurationRuleForBookingScreen($booking, $screen, $plan);
        }

        return $this->resolveEffectiveSplit($screen, $plan, $durationRule);
    }

    /**
     * Helper to resolve matching PricingDurationRule from booking context or engine.
     */
    public function findDurationRuleForBookingScreen(Booking $booking, Screen $screen, ?PricingPlan $plan): ?PricingDurationRule
    {
        if (!$plan) {
            return null;
        }

        // 1. Resolve via PricingRuleEngine if booking dates are available
        if ($booking->start_time && $booking->end_time) {
            $pricingRuleEngine = app(PricingRuleEngine::class);
            $start = \Carbon\Carbon::parse($booking->start_time);
            $end = \Carbon\Carbon::parse($booking->end_time);
            $totalMinutes = max(1, $start->diffInMinutes($end));

            return $pricingRuleEngine->resolveDurationRule($plan, (float) $totalMinutes, 'minute');
        }

        // 2. Check if duration rule ID was stored in booking pricing snapshot JSON
        $breakdown = BookingPricingBreakdown::where('booking_id', $booking->id)
            ->where('screen_id', $screen->id)
            ->first();

        if ($breakdown && !empty($breakdown->pricing_snapshot['applied_rules']['duration_rule']['id'])) {
            return PricingDurationRule::find($breakdown->pricing_snapshot['applied_rules']['duration_rule']['id']);
        }

        return null;
    }

    /**
     * Defensive validation for percentage values.
     *
     * @throws DomainException
     */
    private function validatePercentages(float $illuvision, float $provider, string $context): void
    {
        if ($illuvision < 0.0 || $illuvision > 100.0 || $provider < 0.0 || $provider > 100.0) {
            throw new DomainException("Invalid percentage bounds in {$context}: Illuvision={$illuvision}%, Provider={$provider}%. Must be between 0 and 100.");
        }

        if (round($illuvision + $provider, 2) !== 100.00) {
            throw new DomainException("Percentages do not sum to 100% in {$context}: {$illuvision}% + {$provider}% = " . round($illuvision + $provider, 2) . "%.");
        }
    }
}
