<?php

namespace App\Services;

use App\Domain\Pricing\Contracts\PricingEngineInterface;
use App\Models\Booking;
use App\Models\BookingPricingBreakdown;
use Illuminate\Support\Facades\DB;

/**
 * CampaignPricingService — legacy compatibility wrapper (Phase 0.13).
 *
 * The canonical campaign pricing calculation lives in
 * `App\Domain\Pricing\CampaignPricingEngine` behind `PricingEngineInterface`,
 * extracted in Phase 0.6. This class is retained purely so the existing callers
 * (`BookingController`, `ManualBookingService`, `PaymentService`,
 * `BudgetRecommendationService`, `PricingEngine`) keep working unchanged.
 *
 * WHAT CHANGED IN PHASE 0.13 AND WHY:
 * The constructor previously declared eight dependencies, but only
 * `PricingEngineInterface` was ever used — the other seven
 * (`ScreenPricingResolver`, `DailyPricingCalculator`, `ReferralPricingCalculator`,
 * `FirstBookingDiscountPricingCalculator`, `PricingRuleEngine`, `LoyaltyService`,
 * `SettingsService`) were dead constructor properties that happened to mirror the
 * engine's own signature. They are removed here.
 *
 * That removal is a dependency-surface cleanup only. It changes no calculation,
 * because every one of those seven was already being applied inside the engine:
 * the service was a pass-through, and it still is. It also stops this class from
 * *appearing* to own pricing responsibilities it does not own, which is what made
 * the Domain -> Services coupling hard to see.
 *
 * The remaining genuine pricing work in this class is the request *reconciliation*
 * (`validateCampaignPricing`) and the pricing-breakdown *persistence*
 * (`storePricingBreakdowns`) — neither of which belongs to the calculation engine.
 */
class CampaignPricingService
{
    public function __construct(
        protected PricingEngineInterface $pricingEngine
    ) {}

    /**
     * Calculate campaign pricing preview (no booking created).
     * Delegates directly to canonical CampaignPricingEngine.
     *
     * @param  array  $data  [
     *                       'screen_ids' => int[],
     *                       'start_date' => 'Y-m-d',
     *                       'end_date' => 'Y-m-d',
     *                       'daily_start_time' => 'H:i',
     *                       'daily_end_time' => 'H:i',
     *                       'referral_code_id' => int|null,
     *                       ]
     */
    public function calculateCampaignPreview(array $data): array
    {
        return $this->pricingEngine->calculate($data);
    }

    /**
     * Validate campaign pricing data before booking creation.
     * Ensures the server-side calculation matches the booking request.
     *
     * @return array{valid: bool, errors: array, calculated: array|null}
     */
    public function validateCampaignPricing(array $data): array
    {
        $errors = [];

        try {
            $calculated = $this->calculateCampaignPreview($data);

            // Validate runtime window
            $dailyStartTime = $data['daily_start_time'] ?? '00:00';
            $dailyEndTime = $data['daily_end_time'] ?? '23:59';

            if ($dailyStartTime === $dailyEndTime && $dailyStartTime !== '00:00') {
                $errors[] = 'Invalid runtime window: start and end time cannot be the same.';
            }

            // Validate pricing plans for all screens
            $screenIds = $data['screen_ids'];
            foreach ($screenIds as $screenId) {
                $screen = \App\Models\Screen::with('pricingPlan')->find($screenId);
                if (! $screen) {
                    continue;
                }
                if ($screen->booking_status === 'sold_out') {
                    $errors[] = 'One or more selected screens are no longer available for booking. Please review your selection.';
                }
            }

            // Validate minimum campaign duration for pricing plan eligibility
            $durationErrors = $this->validateMinimumDuration(
                $screenIds,
                $data['start_date'],
                $data['end_date'],
                $dailyStartTime,
                $dailyEndTime
            );
            $errors = array_merge($errors, $durationErrors);

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'calculated' => $calculated,
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'errors' => [$e->getMessage()],
                'calculated' => null,
            ];
        }
    }

    /**
     * Validate that the campaign duration meets the minimum requirement
     * for all selected screens' pricing plans.
     * Delegates to the canonical domain pricing engine.
     */
    public function validateMinimumDuration(
        array $screenIds,
        string $startDate,
        string $endDate,
        string $dailyStartTime = '00:00',
        string $dailyEndTime = '23:59'
    ): array {
        return $this->pricingEngine->validateMinimumDuration(
            $screenIds,
            $startDate,
            $endDate,
            $dailyStartTime,
            $dailyEndTime
        );
    }

    /**
     * Convert minimum duration value to days.
     * Delegates to the canonical domain pricing engine.
     */
    public function convertMinDurationToDays(int|float $value, string $type): float
    {
        return $this->pricingEngine->convertMinDurationToDays($value, $type);
    }

    /**
     * Store pricing breakdowns for a confirmed booking.
     * Includes referral discount snapshot for historical pricing integrity.
     *
     * @param  array  $pricingResult  Result from calculateCampaignPreview()
     */
    public function storePricingBreakdowns(Booking $booking, array $pricingResult): void
    {
        $firstBookingDiscountSnapshot = $pricingResult['first_booking_discount']['settings_snapshot'] ?? null;
        DB::transaction(function () use ($booking, $pricingResult) {
            // Build referral snapshot for historical integrity
            $referralSnapshot = null;
            if ($booking->referral_code_id && $booking->referralCode) {
                $referralCode = $booking->referralCode;
                $referralSnapshot = [
                    'referral_code_id' => $referralCode->id,
                    'referral_code' => $referralCode->code,
                    'referral_discount' => (float) $pricingResult['aggregated']['referral_discount'],
                    'discount_type' => $referralCode->discount_type,
                    'discount_value' => (float) $referralCode->discount_value,
                    'partner_id' => $referralCode->partner_id,
                    'partner_name' => $referralCode->partner?->name,
                    'commission_type' => $referralCode->commission_type,
                    'commission_value' => (float) $referralCode->commission_value,
                ];
            }

            foreach ($pricingResult['per_screen'] as $screenBreakdown) {
                foreach ($screenBreakdown['daily_breakdown'] as $dayBreakdown) {
                    $pricingSnapshot = [
                        'screen_info' => [
                            'screen_id' => $screenBreakdown['screen_id'],
                            'pricing_plan_id' => $screenBreakdown['pricing_plan_id'],
                            'pricing_plan_name' => $screenBreakdown['pricing_plan_name'],
                        ],
                        'day_info' => $dayBreakdown,
                        'campaign_summary' => $pricingResult['campaign_summary'],
                        'aggregated' => $pricingResult['aggregated'],
                        'resolved_rules' => $screenBreakdown['applied_rules'] ?? [],
                    ];

                    // Include referral snapshot in pricing_snapshot for historical integrity
                    if ($referralSnapshot) {
                        $pricingSnapshot['referral'] = $referralSnapshot;
                    }

                    // Include loyalty snapshot in pricing_snapshot for historical integrity
                    if (isset($pricingResult['loyalty'])) {
                        $pricingSnapshot['loyalty'] = $pricingResult['loyalty'];
                    }

                    $validScreenId = $screenBreakdown['screen_id'];
                    $screenExists = \App\Models\Screen::where('id', $validScreenId)->exists();
                    if (! $screenExists) {
                        $validScreenId = \App\Models\Screen::value('id') ?? 1;
                    }

                    BookingPricingBreakdown::create([
                        'booking_id' => $booking->id,
                        'screen_id' => $validScreenId,
                        'pricing_plan_id' => $screenBreakdown['pricing_plan_id'],
                        'campaign_date' => $dayBreakdown['date'],
                        'runtime_hours' => $dayBreakdown['runtime_hours'],
                        'base_price' => $dayBreakdown['base_price'],
                        'peak_charge' => $dayBreakdown['peak_charge'],
                        'weekend_charge' => $dayBreakdown['weekend_charge'],
                        'discount_amount' => $screenBreakdown['screen_total']['discount_amount'],
                        'referral_discount' => $pricingResult['aggregated']['referral_discount'],
                        'subtotal' => $dayBreakdown['day_subtotal'],
                        'first_booking_discount' => ($pricingResult['aggregated']['first_booking_discount'] ?? 0) / max(1, count($screenBreakdown['daily_breakdown'])),
                        'final_total' => $screenBreakdown['screen_total']['subtotal_after_discount'] / max(1, count($screenBreakdown['daily_breakdown'])),
                        'pricing_snapshot' => $pricingSnapshot,
                    ]);
                }
            }
        });
    }
}
