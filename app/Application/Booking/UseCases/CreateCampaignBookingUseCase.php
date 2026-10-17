<?php

namespace App\Application\Booking\UseCases;

use App\Application\Booking\Exceptions\BookingCreationException;
use App\Application\Financial\UseCases\DistributeBookingRevenueUseCase;
use App\Support\Events\Notifications\BookingCreated;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingScreen;
use App\Models\PricingDurationRule;
use App\Models\Screen;
use App\Models\User;
use App\Services\BookingCapacityService;
use App\Services\CampaignPricingService;
use App\Services\InventoryService;
use App\Services\Campaign\LoyaltyService;
use App\Services\RevenueSplitResolverService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CreateCampaignBookingUseCase
 *
 * The canonical application operation behind `POST /api/bookings/campaign`
 * (the website campaign booking channel).
 *
 * WHY A USE CASE:
 * `BookingController::storeCampaign()` was 299 lines and ten concerns in one
 * method: inventory row locking, a sold-out gate, daily-window derivation,
 * minimum-duration validation, unified availability eligibility, authoritative
 * server-side pricing reconciliation, booking creation, loyalty point debit,
 * activity logging, per-screen ownership + revenue-split snapshotting, revenue
 * distribution, pricing-breakdown persistence and event dispatch — all inside a
 * single database transaction. None of that belongs in an HTTP adapter.
 *
 * BEHAVIOURAL CONTRACT — UNCHANGED. This is a relocation, not a redesign:
 *  - The `DB::transaction` boundary is identical, and the inventory row locks
 *    are still taken first, in deterministic id order, before any read.
 *  - The four business rejections still carry their original messages, HTTP 422
 *    status and error payloads (see BookingCreationException for why replacing
 *    early `return`s with throws is observably identical).
 *  - Client-supplied `subtotal` / `discount_amount` / `final_amount` remain
 *    informational only. The stored amounts are still the authoritative values
 *    recomputed by the pricing engine.
 *  - The per-screen even split and `unit_price` arithmetic is preserved
 *    verbatim, including the comment recording the past double-counting bug.
 *  - `BookingCreated` is still dispatched at the end of the transaction, not
 *    after commit. That is pre-existing behaviour and is intentionally not
 *    "fixed" here.
 */
class CreateCampaignBookingUseCase
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected CampaignPricingService $campaignPricingService,
        protected BookingCapacityService $capacityService,
        protected DistributeBookingRevenueUseCase $distributeBookingRevenue,
        protected RevenueSplitResolverService $splitResolver,
        protected LoyaltyService $loyaltyService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Already-validated request payload.
     * @return Booking The created booking, with `bookingScreens.screen` and `bookingMedia` loaded.
     *
     * @throws BookingCreationException
     */
    public function execute(array $validated, User $user): Booking
    {
        return DB::transaction(function () use ($validated, $user) {
            // ── Lock inventory rows (BP6 P0-1 — atomic booking availability) ──
            // Take exclusive row locks on the exact screen/fleet rows in
            // deterministic id order BEFORE any availability/capacity read.
            // A concurrent booking for the same assets blocks here until this
            // transaction commits, so its availability validation observes the
            // committed booking rows and can never over-commit inventory.
            $this->inventoryService->lockScreensForBooking($validated['screen_ids']);

            // Check if any screens are Sold Out / unavailable
            $soldOutScreensCount = Screen::whereIn('id', $validated['screen_ids'])
                ->where('booking_status', 'sold_out')
                ->count();

            if ($soldOutScreensCount > 0) {
                throw new BookingCreationException(
                    'One or more selected screens are no longer available for booking. Please review your selection.'
                );
            }

            $startCarbon = Carbon::parse($validated['start_time']);
            $endCarbon = Carbon::parse($validated['end_time']);
            $startDate = $startCarbon->format('Y-m-d');
            $endDate = $endCarbon->format('Y-m-d');

            $dailyStartTime = $validated['daily_start_time'] ?? ($startCarbon->format('H:i') !== '00:00' ? $startCarbon->format('H:i') : '00:00');
            $dailyEndTime = $validated['daily_end_time'] ?? ($endCarbon->format('H:i') !== '00:00' ? $endCarbon->format('H:i') : '23:59');

            if ($dailyStartTime === '00:00' && $dailyEndTime === '00:00' && $startDate === $endDate) {
                $dailyEndTime = '23:59';
            }

            // Validate minimum campaign duration for each screen's pricing plan
            $durationErrors = $this->campaignPricingService->validateMinimumDuration(
                $validated['screen_ids'],
                $startDate,
                $endDate,
                $dailyStartTime,
                $dailyEndTime
            );

            if (! empty($durationErrors)) {
                throw new BookingCreationException(
                    'The selected campaign duration does not meet the minimum requirement for one or more selected screens.',
                    422,
                    ['duration_errors' => $durationErrors]
                );
            }

            // ── Unified Booking Eligibility Check ─────────────────────────
            // Evaluates Daily Quota + Fixed Screen Time Conflicts + Mobile Fleet Capacity
            $items = [];
            foreach ($validated['screen_ids'] as $screenId) {
                $items[] = [
                    'screen_id' => $screenId,
                    'quantity' => max(1, (int) ($validated['quantities'][$screenId] ?? 1)),
                ];
            }

            $eligibility = $this->capacityService->checkEligibility(
                $items,
                $validated['start_time'],
                $validated['end_time']
            );

            if (! $eligibility->isEligible()) {
                throw new BookingCreationException(
                    $eligibility->getFirstErrorMessage(),
                    422,
                    $eligibility->toArray()
                );
            }

            // ── Validate server-side pricing ────────────────
            $pricingValidation = $this->campaignPricingService->validateCampaignPricing([
                'screen_ids' => $validated['screen_ids'],
                'quantities' => $validated['quantities'] ?? [],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'daily_start_time' => $dailyStartTime,
                'daily_end_time' => $dailyEndTime,
                'referral_code_id' => $validated['referral_code_id'] ?? null,
                'redeem_points' => $validated['redeem_points'] ?? 0,
                'user' => $user,
            ]);

            if (! $pricingValidation['valid']) {
                throw new BookingCreationException(
                    'Pricing validation failed: '.implode(' ', $pricingValidation['errors'])
                );
            }

            // ── Authoritative amounts (BP5 §25–26) ─────────────────────
            // The booking is priced by CampaignPricingService from the exact
            // screen_ids + quantities + dates. The client-supplied
            // subtotal/discount/final_amount are informational only and are
            // NEVER trusted for the stored or charged amount — so a stale
            // recommendation or price change is always reconciled to the
            // current authoritative price at booking time.
            $authoritativeAgg = $pricingValidation['calculated']['aggregated'] ?? null;
            $authoritativeSubtotal = (float) ($authoritativeAgg['subtotal_before_discount'] ?? 0);
            $authoritativeDiscount = (float) ($authoritativeAgg['total_discount'] ?? 0);
            $authoritativeReferralDiscount = (float) ($authoritativeAgg['referral_discount'] ?? 0);
            $authoritativeFinal = (float) ($authoritativeAgg['final_total'] ?? 0);

            $pricingSnapshot = null;
            if (isset($pricingValidation['calculated']['aggregated'])) {
                $agg = $pricingValidation['calculated']['aggregated'];
                $pricingSnapshot = [
                    'base_price' => $agg['base_price'],
                    'screen_count' => $agg['screen_count'],
                    'screen_discount' => $agg['screen_discount'],
                    'duration_discount' => $agg['duration_discount'],
                    'combined_discount' => $agg['combined_discount'],
                    'peak_adjustment' => $agg['peak_adjustment'],
                    'weekend_adjustment' => $agg['weekend_adjustment'],
                    'first_booking_discount' => $agg['first_booking_discount'],
                    'referral_discount' => $agg['referral_discount'],
                    'reward_points_used' => $agg['reward_points_used'] ?? 0,
                    'reward_discount' => $agg['reward_discount'] ?? 0.00,
                    'final_amount' => $agg['final_amount'],
                ];
            }

            // Create the campaign booking
            $booking = Booking::create([
                'user_id' => $user->id,
                'pricing_plan_id' => $validated['pricing_plan_id'] ?? null,
                'referral_code_id' => $validated['referral_code_id'] ?? null,
                'title' => $validated['campaign_title'],
                'campaign_title' => $validated['campaign_title'],
                'advertiser_name' => $user->name,
                'advertiser_email' => $user->email,
                'advertiser_phone' => $user->phone,
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'ad_frequency' => $validated['ad_frequency'],
                'price' => $authoritativeSubtotal,
                'subtotal' => $authoritativeSubtotal,
                'discount_amount' => $authoritativeDiscount,
                'referral_discount' => $authoritativeReferralDiscount,
                'reward_points_earned' => 0,
                'reward_points_redeemed' => $authoritativeAgg['reward_points_used'] ?? 0,
                'reward_discount_amount' => $authoritativeAgg['reward_discount'] ?? 0.00,
                'final_amount' => $authoritativeFinal,
                'total_screens' => $validated['total_screens'],
                'status' => Booking::STATUS_PAYMENT_PENDING,
                'notes' => $validated['notes'] ?? null,
                'source' => 'website',

                // Currency tracking at booking time
                'currency_code' => $validated['currency_code'] ?? 'INR',
                'exchange_rate_used' => $validated['exchange_rate_used'] ?? null,
                'display_amount' => $validated['display_amount'] ?? null,
                'pricing_snapshot' => $pricingSnapshot,

                // Advertisement Category
                'advertisement_category_id' => $validated['advertisement_category_id'],
                'advertisement_category_name' => DB::table('advertisement_categories')
                    ->where('id', $validated['advertisement_category_id'])
                    ->value('name'),
            ]);

            // If user redeemed points, immediately debit them from account
            if ($booking->reward_points_redeemed > 0) {
                $this->loyaltyService->debitRedeemedPoints($booking, $booking->reward_points_redeemed);
            }

            // Log activity
            BookingActivity::create([
                'booking_id' => $booking->id,
                'type' => 'created',
                'label' => 'Booking Created',
                'description' => 'Campaign booking created via website',
                'performed_by' => $user->id,
            ]);

            // Create booking_screen records with ownership snapshots
            foreach ($validated['screen_ids'] as $screenId) {
                $screen = Screen::with(['city', 'screenProvider'])->find($screenId);
                $screenQty = max(1, (int) ($validated['quantities'][$screenId] ?? 1));
                // The authoritative subtotal is already quantity-scaled by the
                // oracle, so each screen's share is the even split — it must
                // NOT be multiplied by quantity again (that double-counted
                // mobile fleet units). unit_price is the per-unit share.
                $perScreenSubtotal = $authoritativeSubtotal / count($validated['screen_ids']);
                $lineSubtotal = round($perScreenSubtotal, 2);
                $unitPrice = $screenQty > 0 ? round($perScreenSubtotal / $screenQty, 2) : 0;

                $screenData = [
                    'booking_id' => $booking->id,
                    'screen_id' => $screenId,
                    'city_id' => $screen->city_id ?? null,
                    'quantity' => $screenQty,
                    'unit_price' => round($unitPrice, 2),
                    'line_subtotal' => round($lineSubtotal, 2),
                    'subtotal' => round($lineSubtotal, 2),
                    'screen_category_id' => $screen->screen_category_id,
                    'screen_category_name' => $screen->screenCategory?->name ?? $screen->screen_type,
                    'pricing_snapshot' => [
                        'screen_name' => $screen->name,
                        'screen_code' => $screen->screen_code,
                        'location' => $screen->location_name,
                        'city' => $screen->city->name ?? null,
                        'screen_category_id' => $screen->screen_category_id,
                        'screen_category_name' => $screen->screenCategory?->name ?? $screen->screen_type,
                    ],
                    'status' => 'pending',
                    // Ownership snapshot
                    'owner_type' => $screen->owner_type ?? Screen::OWNER_TYPE_ILLUVISION,
                    'screen_provider_id' => $screen->screen_provider_id,
                    'screen_provider_name' => $screen->screenProvider?->provider_name,
                ];

                // Capture revenue split percentages if owned by a provider
                if ($screen->isOwnedByProvider() && $screen->screenProvider) {
                    $plan = $screen->relationLoaded('pricingPlan') ? $screen->pricingPlan : $screen->pricingPlan()->first();
                    $durationRule = null;
                    if (! empty($pricingValidation['calculated']['per_screen_breakdowns'])) {
                        foreach ($pricingValidation['calculated']['per_screen_breakdowns'] as $psb) {
                            if ((int) ($psb['screen_id'] ?? 0) === (int) $screen->id && ! empty($psb['applied_discount_rule']['id'])) {
                                $durationRule = PricingDurationRule::find($psb['applied_discount_rule']['id']);
                                break;
                            }
                        }
                    }

                    $splitResult = $this->splitResolver->resolveEffectiveSplit($screen, $plan, $durationRule);
                    $screenData['illuvision_percentage'] = $splitResult['illuvision_percentage'];
                    $screenData['screen_provider_percentage'] = $splitResult['screen_provider_percentage'];
                    $screenData['gross_revenue'] = round($lineSubtotal, 2);
                }

                BookingScreen::create($screenData);
            }

            // Process revenue distribution snapshots for all screens
            $this->distributeBookingRevenue->execute($booking);

            // Store pricing breakdowns for historical record
            if ($pricingValidation['calculated']) {
                $this->campaignPricingService->storePricingBreakdowns($booking, $pricingValidation['calculated']);

                // Save first booking discount fields to the booking for reporting
                $fbdData = $pricingValidation['calculated']['first_booking_discount'] ?? [];
                if (! empty($fbdData['eligible'])) {
                    $booking->update([
                        'first_booking_discount_applied' => true,
                        'first_booking_discount_amount' => $fbdData['amount'] ?? 0,
                    ]);
                }
            }

            $booking->load(['bookingScreens.screen', 'bookingMedia']);

            // Dispatch notification event
            event(new BookingCreated($booking));

            return $booking;
        });
    }
}
