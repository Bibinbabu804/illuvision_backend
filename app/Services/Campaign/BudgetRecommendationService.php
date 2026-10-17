<?php

namespace App\Services\Campaign;

use App\Enums\AssetType;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\State;
use App\Models\City;
use App\Services\Pricing\ScreenPricingResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\CampaignPricingService;
use App\Services\InventoryService;

/**
 * BP3 Budget Planner Multi-Strategy Recommendation Engine.
 *
 * Given a budget, geography, screen categories, campaign preference and a date
 * window, this service discovers ACTUAL bookable inventory and generates up to
 * three distinct recommendation strategies:
 *   1. Best Match (preferred_duration) — preserves requested duration & criteria.
 *   2. More Time (extended_duration) — extends duration to utilize unused budget.
 *   3. More Coverage (expanded_coverage) — expands screen coverage within budget.
 *
 * Architectural rules:
 *  - CampaignPricingService is the ONLY pricing oracle. No price is ever
 *    calculated manually inside this engine.
 *  - InventoryService is the availability oracle (fixed = binary bookable,
 *    mobile = fleet_size - reserved units over the complete date range).
 *  - Fixed assets resolve through country → state (city.state_id) → city.
 *  - Mobile assets resolve through country → coverage_states → coverage_cities.
 *  - Screen categories apply to BOTH fixed and mobile assets.
 *  - Coverage locations are geography, NOT fleet quantity.
 *  - Candidate generation is bounded — no combinatorial subset search.
 */
class BudgetRecommendationService
{
    /**
     * Booking statuses that consume mobile fleet capacity (mirrors
     * InventoryService::CAPACITY_STATUSES).
     */
    private const CAPACITY_STATUSES = [
        'payment_pending',
        'pending_approval',
        'approved',
        'scheduled',
        'running',
    ];

    /** Max oracle pricing calls per package (bounds the trim/top-up loops). */
    private const MAX_PRICING_CALLS_PER_PACKAGE = 25;

    /** Max duration candidates evaluated for extended duration search. */
    private const MAX_EXTENDED_DURATION_CANDIDATES = 6;

    /**
     * Per-request oracle memo. Identical pricing inputs — same
     * screen_ids + quantities + dates — always produce the identical
     * authoritative CampaignPricingService result.
     */
    private array $pricingCache = [];

    public function __construct(
        protected CampaignPricingService $campaignPricingService,
        protected InventoryService $inventoryService,
        protected ScreenPricingResolver $screenPricingResolver,
    ) {}

    /**
     * Generate budget-planner recommendations.
     *
     * @param array $input [
     *   'budget' => float,
     *   'country_id' => int,
     *   'state_ids' => int[],          // [] => all states of country
     *   'city_ids' => int[],           // [] => all cities of the selected states
     *   'screen_category_ids' => int[],// [] => all categories
     *   'campaign_preference' => 'more_screens'|'more_duration',
     *   'campaign_mode' => 'multi_day'|'intraday',
     *   'start_date' => 'Y-m-d',
     *   'end_date' => 'Y-m-d',
     *   'daily_start_time' => 'H:i',   // e.g. '10:00'
     *   'daily_end_time' => 'H:i',     // e.g. '10:30'
     *   'duration_minutes' => int,     // canonical minutes
     *   'currency_code' => ?string,    // display context only
     *   'country_code' => ?string,     // visitor context only
     * ]
     * @return array RecommendationResponse
     */
    public function calculate(array $input): array
    {
        $startedAt = microtime(true);

        $budget = (float) $input['budget'];
        $countryId = (int) $input['country_id'];
        $preference = $input['campaign_preference'] ?? 'more_screens';
        $startDate = $input['start_date'];
        $endDate = $input['end_date'];
        $campaignMode = $input['campaign_mode'] ?? 'multi_day';
        $dailyStartTime = $input['daily_start_time'] ?? '00:00';
        $dailyEndTime = $input['daily_end_time'] ?? '23:59';

        // Per-request oracle memoization scope.
        $this->pricingCache = [];

        // ── Resolve geography ─────────────────────────────────────────
        $stateIds = $this->resolveStateIds($countryId, $input['state_ids'] ?? []);
        $cityIds = $this->resolveCityIds($countryId, $stateIds, $input['city_ids'] ?? []);
        $categoryIds = array_values(array_filter(array_map('intval', $input['screen_category_ids'] ?? [])));

        $requestedDays = $this->daysBetween($startDate, $endDate);
        $durationMinutes = (int) ($input['duration_minutes'] ?? 0);
        if ($durationMinutes <= 0) {
            if ($campaignMode === 'intraday') {
                $startTs = strtotime($startDate . ' ' . $dailyStartTime . ':00');
                $endTs = strtotime($endDate . ' ' . $dailyEndTime . ':00');
                $durationMinutes = max(1, (int) round(($endTs - $startTs) / 60));
            } else {
                $durationMinutes = $requestedDays * 1440;
            }
        }

        $durationLabel = $this->formatDurationMinutes($durationMinutes);

        $metrics = [
            'country_id' => $countryId,
            'state_count' => count($stateIds),
            'city_count' => count($cityIds),
            'category_count' => count($categoryIds),
            'preference' => $preference,
            'campaign_mode' => $campaignMode,
            'duration_days' => $requestedDays,
            'duration_minutes' => $durationMinutes,
        ];

        // ── 1. Primary inventory discovery ────────────────────────────
        $candidates = $this->discoverAssets($countryId, $stateIds, $cityIds, $categoryIds);
        $metrics['candidate_count'] = $candidates->count();

        if ($candidates->isEmpty()) {
            $reason = 'NO_INVENTORY';
            $note = 'No inventory matches the selected locations and screen categories.';
            if (!empty($categoryIds)) {
                $locationOnly = $this->discoverAssets($countryId, $stateIds, $cityIds, []);
                if ($locationOnly->isNotEmpty()) {
                    $reason = 'NO_CATEGORY_MATCH';
                    $note = 'None of the selected types of places are available in the selected locations. Try other types of places or widen the location.';
                }
            }
            return $this->emptyResponse($budget, $preference, $input, $note, $reason);
        }

        // ── 2. Per-asset duration eligibility for requested duration ──
        $minMinutesByAsset = $this->minMinutesByAsset($candidates);
        $durationEligible = $candidates
            ->filter(fn (Screen $asset) => ($minMinutesByAsset[$asset->id] ?? 1440) <= $durationMinutes)
            ->values();
        $metrics['min_feasible_minutes'] = $this->shortestFeasibleMinutes($durationEligible);
        $metrics['duration_filtered_count'] = $durationEligible->count();

        if ($durationEligible->isEmpty()) {
            return $this->emptyResponse(
                $budget,
                $preference,
                $input,
                "No campaign package is available for a {$durationLabel} campaign in the selected locations and categories. The matching screens require a longer minimum campaign duration.",
                'DURATION_NOT_SUPPORTED'
            );
        }

        // ── 3. Date-range availability over requested window ──────────
        $availableUnits = $this->availableUnitsByAsset($durationEligible, $startDate, $endDate, $dailyStartTime, $dailyEndTime, $campaignMode);
        $eligible = $durationEligible->filter(fn (Screen $asset) => ($availableUnits[$asset->id] ?? 0) > 0)->values();
        $metrics['availability_filtered_count'] = $eligible->count();

        if ($eligible->isEmpty()) {
            $isMobileOnly = $durationEligible->every(fn (Screen $asset) => $asset->is_mobile);
            $reason = $isMobileOnly ? 'INSUFFICIENT_MOBILE_UNITS' : 'NO_AVAILABILITY';
            $note = $isMobileOnly
                ? 'The mobile fleets in the selected locations are fully booked for your dates. Try different dates or choose fixed screens instead.'
                : 'The matching inventory is fully booked or sold out for the selected dates. Try different dates, wider locations, or more screen categories.';
            return $this->emptyResponse($budget, $preference, $input, $note, $reason);
        }

        $unitDailyPrice = $this->heuristicUnitDailyPrices($eligible);
        $pricingCalls = 0;
        $packages = [];

        // ── 4. STRATEGY 1: Best Match (preferred_duration) ────────────
        // Preserves requested duration, location, categories and preference.
        $bestMatchResult = $this->buildPackage([
            'budget' => $budget,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_start_time' => $dailyStartTime,
            'daily_end_time' => $dailyEndTime,
            'duration_days' => $requestedDays,
            'duration_minutes' => $durationMinutes,
            'campaign_mode' => $campaignMode,
            'eligible' => $eligible,
            'available_units' => $availableUnits,
            'unit_daily_price' => $unitDailyPrice,
            'preference' => $preference,
        ], 'Best Match', 'preferred_duration');

        $pricingCalls += $bestMatchResult['pricing_calls'] ?? 0;
        $bestMatchPkg = $bestMatchResult['package'];

        if ($bestMatchPkg !== null) {
            $packages[] = $bestMatchPkg;
        }

        // ── 5. STRATEGY 2: More Time (extended_duration) ──────────────
        // If budget permits, explore longer campaign durations on the same criteria.
        $bestExtendedPkg = null;

        if ($campaignMode === 'intraday') {
            $extendedCandidates = $this->candidateExtendedIntradayDurations($durationMinutes, $candidates);

            foreach ($extendedCandidates as $extMinutes) {
                $extDailyEndTime = date('H:i', strtotime($startDate . ' ' . $dailyStartTime . ':00 +' . $extMinutes . ' minutes'));

                // Assets eligible for this extended duration
                $extDurationAssets = $candidates
                    ->filter(fn (Screen $asset) => ($minMinutesByAsset[$asset->id] ?? 1440) <= $extMinutes)
                    ->values();

                if ($extDurationAssets->isEmpty()) {
                    continue;
                }

                // Verify full availability over [dailyStartTime, extDailyEndTime]
                $extAvailUnits = $this->availableUnitsByAsset($extDurationAssets, $startDate, $endDate, $dailyStartTime, $extDailyEndTime, 'intraday');
                $extEligible = $extDurationAssets->filter(fn (Screen $asset) => ($extAvailUnits[$asset->id] ?? 0) > 0)->values();

                if ($extEligible->isEmpty()) {
                    continue;
                }

                $extDailyPrice = $this->heuristicUnitDailyPrices($extEligible);
                $extResult = $this->buildPackage([
                    'budget' => $budget,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'daily_start_time' => $dailyStartTime,
                    'daily_end_time' => $extDailyEndTime,
                    'duration_days' => $requestedDays,
                    'duration_minutes' => $extMinutes,
                    'campaign_mode' => 'intraday',
                    'eligible' => $extEligible,
                    'available_units' => $extAvailUnits,
                    'unit_daily_price' => $extDailyPrice,
                    'preference' => 'more_duration',
                ], 'More Time', 'extended_duration');

                $pricingCalls += $extResult['pricing_calls'] ?? 0;
                $pkg = $extResult['package'];

                if ($pkg !== null && (float) $pkg['pricing']['final_amount'] <= $budget) {
                    if ($bestExtendedPkg === null || $extMinutes > (int) $bestExtendedPkg['campaign_summary']['duration_minutes']) {
                        $bestExtendedPkg = $pkg;
                    }
                }
            }
        } else {
            $extendedCandidates = $this->candidateExtendedDurations($requestedDays, $candidates);

            foreach ($extendedCandidates as $extDays) {
                $extEndDate = Carbon::parse($startDate)->addDays($extDays - 1)->format('Y-m-d');
                $extMinutes = $extDays * 1440;

                // Assets eligible for this extended duration
                $extDurationAssets = $candidates
                    ->filter(fn (Screen $asset) => ($minMinutesByAsset[$asset->id] ?? 1440) <= $extMinutes)
                    ->values();

                if ($extDurationAssets->isEmpty()) {
                    continue;
                }

                // Verify full date-range availability over [startDate, extEndDate]
                $extAvailUnits = $this->availableUnitsByAsset($extDurationAssets, $startDate, $extEndDate, '00:00', '23:59', 'multi_day');
                $extEligible = $extDurationAssets->filter(fn (Screen $asset) => ($extAvailUnits[$asset->id] ?? 0) > 0)->values();

                if ($extEligible->isEmpty()) {
                    continue;
                }

                $extDailyPrice = $this->heuristicUnitDailyPrices($extEligible);
                $extResult = $this->buildPackage([
                    'budget' => $budget,
                    'start_date' => $startDate,
                    'end_date' => $extEndDate,
                    'daily_start_time' => '00:00',
                    'daily_end_time' => '23:59',
                    'duration_days' => $extDays,
                    'duration_minutes' => $extMinutes,
                    'campaign_mode' => 'multi_day',
                    'eligible' => $extEligible,
                    'available_units' => $extAvailUnits,
                    'unit_daily_price' => $extDailyPrice,
                    'preference' => 'more_duration',
                ], 'More Time', 'extended_duration');

                $pricingCalls += $extResult['pricing_calls'] ?? 0;
                $pkg = $extResult['package'];

                if ($pkg !== null && (float) $pkg['pricing']['final_amount'] <= $budget) {
                    if ($bestExtendedPkg === null || $extDays > (int) $bestExtendedPkg['campaign_summary']['duration_days']) {
                        $bestExtendedPkg = $pkg;
                    }
                }
            }
        }

        if ($bestExtendedPkg !== null) {
            $packages[] = $bestExtendedPkg;
        }

        // ── 6. STRATEGY 3: More Coverage (expanded_coverage) ──────────
        // Preserves requested duration while searching for additional inventory
        // (nearby/state cities, compatible categories, mixed fixed + mobile).
        $expandedPkg = $this->generateExpandedCoveragePackage(
            $budget,
            $countryId,
            $stateIds,
            $cityIds,
            $categoryIds,
            $startDate,
            $endDate,
            $requestedDays,
            $durationMinutes,
            $dailyStartTime,
            $dailyEndTime,
            $campaignMode,
            $pricingCalls
        );

        if ($expandedPkg !== null) {
            $packages[] = $expandedPkg;
        }

        // ── 7. Deduplicate identical packages ─────────────────────────
        // Remove duplicate packages (same asset set, quantities, dates, times, final amount).
        $packages = $this->dedupePackages($packages);

        $metrics['pricing_calls'] = $pricingCalls;
        $metrics['valid_package_count'] = count($packages);
        $metrics['execution_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        $this->logMetrics($metrics);

        // ── 8. Assemble response ──────────────────────────────────────
        $inventoryNote = null;
        $noResult = null;
        if (count($packages) === 0) {
            $inventoryNote = $this->buildInventoryNote($budget, 0);
            $noResult = [
                'reason' => 'BUDGET_TOO_LOW',
                'message' => $inventoryNote,
            ];
        } elseif (count($packages) < 3) {
            $inventoryNote = $this->buildInventoryNote($budget, count($packages));
        }

        return [
            'request' => [
                'budget' => round($budget, 2),
                'country_id' => $countryId,
                'state_ids' => $stateIds,
                'city_ids' => $cityIds,
                'screen_category_ids' => $categoryIds,
                'campaign_preference' => $preference,
                'campaign_mode' => $campaignMode,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'daily_start_time' => $dailyStartTime,
                'daily_end_time' => $dailyEndTime,
                'duration_minutes' => $durationMinutes,
                'currency_code' => $input['currency_code'] ?? null,
                'country_code' => $input['country_code'] ?? null,
            ],
            'recommendations' => $packages,
            'inventory_note' => $inventoryNote,
            'no_result' => $noResult,
        ];
    }

    /**
     * Generate the Expanded Screen Coverage strategy package.
     */
    private function generateExpandedCoveragePackage(
        float $budget,
        int $countryId,
        array $stateIds,
        array $cityIds,
        array $categoryIds,
        string $startDate,
        string $endDate,
        int $requestedDays,
        int $durationMinutes,
        string $dailyStartTime,
        string $dailyEndTime,
        string $campaignMode,
        int &$pricingCalls
    ): ?array {
        // Expand geography: all cities in selected states
        $allStateCityIds = $this->resolveCityIds($countryId, $stateIds, []);

        // Expand categories: include compatible category affinities
        $compatibleCategoryIds = $this->resolveCompatibleCategoryIds($categoryIds);

        // Discovery across expanded geography & categories
        $expandedCandidates = $this->discoverAssets($countryId, $stateIds, $allStateCityIds, $compatibleCategoryIds);
        if ($expandedCandidates->isEmpty()) {
            return null;
        }

        $minMinutesByAsset = $this->minMinutesByAsset($expandedCandidates);
        $durationEligible = $expandedCandidates
            ->filter(fn (Screen $asset) => ($minMinutesByAsset[$asset->id] ?? 1440) <= $durationMinutes)
            ->values();

        if ($durationEligible->isEmpty()) {
            return null;
        }

        $availableUnits = $this->availableUnitsByAsset($durationEligible, $startDate, $endDate, $dailyStartTime, $dailyEndTime, $campaignMode);
        $eligible = $durationEligible->filter(fn (Screen $asset) => ($availableUnits[$asset->id] ?? 0) > 0)->values();

        if ($eligible->isEmpty()) {
            return null;
        }

        $unitDailyPrice = $this->heuristicUnitDailyPrices($eligible);

        $result = $this->buildPackage([
            'budget' => $budget,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_start_time' => $dailyStartTime,
            'daily_end_time' => $dailyEndTime,
            'duration_days' => $requestedDays,
            'duration_minutes' => $durationMinutes,
            'campaign_mode' => $campaignMode,
            'eligible' => $eligible,
            'available_units' => $availableUnits,
            'unit_daily_price' => $unitDailyPrice,
            'preference' => 'more_screens',
        ], 'More Coverage', 'expanded_coverage');

        $pricingCalls += $result['pricing_calls'] ?? 0;

        return $result['package'];
    }

    /**
     * Resolve compatible category IDs based on the category affinity mapping.
     */
    private function resolveCompatibleCategoryIds(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return []; // All categories
        }

        $categories = ScreenCategory::whereIn('id', $categoryIds)->get();
        $affinities = config('recommendations.category_affinity', [
            'mall-display' => ['mall-display', 'theatre-screen', 'restaurant-display', 'mall', 'theatre'],
            'theatre-screen' => ['theatre-screen', 'mall-display', 'restaurant-display', 'theatre', 'mall'],
            'restaurant-display' => ['restaurant-display', 'mall-display', 'theatre-screen'],
            'mall' => ['mall', 'theatre', 'mall-display', 'theatre-screen'],
            'theatre' => ['theatre', 'mall', 'theatre-screen', 'mall-display'],
            'bus-terminal' => ['bus-terminal', 'bus-stop-shelter-display', 'bus-screen', 'airport-screen'],
            'bus-stop-shelter-display' => ['bus-stop-shelter-display', 'bus-terminal', 'bus-screen'],
            'bus-screen' => ['bus-screen', 'bus-terminal', 'bus-stop-shelter-display'],
            'airport-screen' => ['airport-screen', 'bus-terminal'],
            'led-billboard' => ['led-billboard', 'outdoor-display', 'digital-totem'],
            'outdoor-display' => ['outdoor-display', 'led-billboard', 'digital-totem'],
            'digital-totem' => ['digital-totem', 'outdoor-display', 'led-billboard'],
            'led-van' => ['led-van', 'led-truck', 'backpack-team', 'taxi'],
            'led-truck' => ['led-truck', 'led-van', 'backpack-team'],
            'backpack-team' => ['backpack-team', 'led-van', 'led-truck'],
            'taxi' => ['taxi', 'led-van', 'backpack-team'],
            'event-display' => ['event-display', 'digital-totem', 'outdoor-display'],
            'standard-display' => ['standard-display', 'digital-totem', 'outdoor-display'],
        ]);

        $compatibleSlugs = [];
        foreach ($categories as $cat) {
            $slug = $cat->slug;
            $compatibleSlugs[] = $slug;
            if (isset($affinities[$slug])) {
                foreach ($affinities[$slug] as $affSlug) {
                    $compatibleSlugs[] = $affSlug;
                }
            }
        }

        $compatibleSlugs = array_values(array_unique($compatibleSlugs));

        return ScreenCategory::whereIn('slug', $compatibleSlugs)->pluck('id')->all();
    }

    /**
     * Derive candidate extended durations (> requestedDays) from pricing plans
     * and duration discount rules.
     */
    private function candidateExtendedDurations(int $requestedDays, Collection $assets): array
    {
        $candidates = [];

        // Rule breakpoints and plan minimums > requestedDays
        foreach ($assets as $asset) {
            $plan = $asset->pricingPlan;
            if (!$plan || !$plan->is_active) {
                continue;
            }

            $planMin = $this->planMinimumDays($plan);
            if ($planMin > $requestedDays && $planMin <= 180) {
                $candidates[] = $planMin;
            }

            foreach ($plan->durationRules()->where('status', 'active')->get() as $rule) {
                $breakpoint = (int) ceil($this->ruleDays((float) $rule->minimum_duration, $rule->duration_unit));
                if ($breakpoint > $requestedDays && $breakpoint <= 180) {
                    $candidates[] = $breakpoint;
                }
            }
        }

        // Natural duration multipliers & step increments derived from requested duration
        $multipliers = [1.25, 1.5, 1.75, 2.0];
        foreach ($multipliers as $m) {
            $val = (int) round($requestedDays * $m);
            if ($val > $requestedDays && $val <= 180) {
                $candidates[] = $val;
            }
        }

        $steps = [7, 14, 15, 30, 45, 60];
        foreach ($steps as $s) {
            $val = $requestedDays + $s;
            if ($val > $requestedDays && $val <= 180) {
                $candidates[] = $val;
            }
        }

        // Standard milestone days
        foreach ([45, 60, 75, 90, 120] as $standard) {
            if ($standard > $requestedDays) {
                $candidates[] = $standard;
            }
        }

        $candidates = array_values(array_unique(array_map('intval', $candidates)));
        sort($candidates);

        if (count($candidates) > self::MAX_EXTENDED_DURATION_CANDIDATES) {
            $candidates = array_slice($candidates, 0, self::MAX_EXTENDED_DURATION_CANDIDATES);
        }

        return $candidates;
    }

    /**
     * Derive candidate extended intraday durations (> requestedMinutes) from pricing plans
     * and standard minute increments.
     */
    private function candidateExtendedIntradayDurations(int $requestedMinutes, Collection $assets): array
    {
        $candidates = [];

        foreach ($assets as $asset) {
            $plan = $asset->pricingPlan;
            if (!$plan || !$plan->is_active) {
                continue;
            }

            $planMin = $this->planMinimumMinutes($plan);
            if ($planMin > $requestedMinutes && $planMin <= 1440) {
                $candidates[] = $planMin;
            }

            foreach ($plan->durationRules()->where('status', 'active')->get() as $rule) {
                $breakpoint = (int) ceil($this->ruleMinutes((float) $rule->minimum_duration, $rule->duration_unit));
                if ($breakpoint > $requestedMinutes && $breakpoint <= 1440) {
                    $candidates[] = $breakpoint;
                }
            }
        }

        $standardMinuteMilestones = [30, 45, 60, 90, 120, 180, 240, 360, 480];
        foreach ($standardMinuteMilestones as $m) {
            if ($m > $requestedMinutes) {
                $candidates[] = $m;
            }
        }

        $multipliers = [1.5, 2.0];
        foreach ($multipliers as $mul) {
            $val = (int) round($requestedMinutes * $mul);
            if ($val > $requestedMinutes && $val <= 1440) {
                $candidates[] = $val;
            }
        }

        $candidates = array_values(array_unique(array_map('intval', $candidates)));
        sort($candidates);

        if (count($candidates) > self::MAX_EXTENDED_DURATION_CANDIDATES) {
            $candidates = array_slice($candidates, 0, self::MAX_EXTENDED_DURATION_CANDIDATES);
        }

        return $candidates;
    }

    /**
     * Build a single package for the given duration using bounded greedy
     * selection, then authoritative pricing + trimming.
     *
     * @return array{package: array|null, pricing_calls: int}
     */
    private function buildPackage(array $ctx, string $label, string $strategy): array
    {
        $budget = $ctx['budget'];
        $startDate = $ctx['start_date'];
        $endDate = $ctx['end_date'];
        $dailyStartTime = $ctx['daily_start_time'] ?? '00:00';
        $dailyEndTime = $ctx['daily_end_time'] ?? '23:59';
        $days = $ctx['duration_days'];
        $durationMinutes = $ctx['duration_minutes'] ?? ($days * 1440);
        $campaignMode = $ctx['campaign_mode'] ?? 'multi_day';
        $eligible = $ctx['eligible'];
        $availableUnits = $ctx['available_units'];
        $unitDailyPrice = $ctx['unit_daily_price'];

        // Order: fixed screens first, then mobile fleets; cheapest unit/day first.
        $ordered = $eligible
            ->sortBy(function (Screen $asset) use ($unitDailyPrice) {
                return [
                    $asset->is_mobile ? 1 : 0,
                    $unitDailyPrice[$asset->id] ?? PHP_FLOAT_MAX,
                ];
            })
            ->values();

        $selected = [];   // asset_id => ['asset' => Screen, 'quantity' => int]
        $heuristicUsed = 0.0;
        $pricingCalls = 0;

        // ── Greedy selection using heuristic costs ────────────────────
        foreach ($ordered as $asset) {
            $daily = $unitDailyPrice[$asset->id] ?? PHP_FLOAT_MAX;
            $avail = $availableUnits[$asset->id] ?? 0;

            if ($asset->is_mobile) {
                $quantity = min($avail, max(1, (int) floor(($budget - $heuristicUsed) / max(0.01, $daily * $days))));
                if ($quantity < 1) {
                    continue;
                }
                $cost = $daily * $days * $quantity;
            } else {
                $cost = $daily * $days;
                if ($heuristicUsed + $cost > $budget) {
                    if ($heuristicUsed > 0) {
                        break;
                    }
                    continue;
                }
                $quantity = 1;
            }

            $selected[$asset->id] = ['asset' => $asset, 'quantity' => $quantity];
            $heuristicUsed += $cost;
        }

        if (empty($selected)) {
            return [
                'package' => null,
                'pricing_calls' => 0,
            ];
        }

        // ── Authoritative pricing + bounded trim/top-up ───────────────
        $pricing = $this->pricePackage($selected, $startDate, $endDate, $dailyStartTime, $dailyEndTime);
        $pricingCalls++;
        $finalAmount = $pricing['aggregated']['final_amount'] ?? PHP_FLOAT_MAX;

        $attempts = 0;
        while ($attempts < self::MAX_PRICING_CALLS_PER_PACKAGE) {
            $attempts++;

            if ($finalAmount > $budget) {
                if (!$this->trimPackage($selected, $unitDailyPrice, $days)) {
                    break;
                }
                $pricing = $this->pricePackage($selected, $startDate, $endDate, $dailyStartTime, $dailyEndTime);
                $pricingCalls++;
                $finalAmount = $pricing['aggregated']['final_amount'] ?? PHP_FLOAT_MAX;
                continue;
            }

            // Under budget: top up with more assets/units if utilization allows
            $lastChange = null;
            $added = $this->topUpPackage($selected, $ordered, $availableUnits, $unitDailyPrice, $days, $budget, $finalAmount, $lastChange);
            if (!$added) {
                break;
            }

            $pricing = $this->pricePackage($selected, $startDate, $endDate, $dailyStartTime, $dailyEndTime);
            $pricingCalls++;
            $finalAmount = $pricing['aggregated']['final_amount'] ?? PHP_FLOAT_MAX;

            if ($finalAmount > $budget) {
                $this->revertTopUp($selected, $lastChange);
                $pricing = $this->pricePackage($selected, $startDate, $endDate, $dailyStartTime, $dailyEndTime);
                $pricingCalls++;
                $finalAmount = $pricing['aggregated']['final_amount'] ?? PHP_FLOAT_MAX;
                break;
            }
        }

        if (empty($selected) || $finalAmount > $budget) {
            return [
                'package' => null,
                'pricing_calls' => $pricingCalls,
            ];
        }

        // ── Final date-range availability validation via oracle ───────
        $items = [];
        foreach ($selected as $assetId => $entry) {
            $items[] = ['asset_id' => $assetId, 'quantity' => $entry['quantity']];
        }
        $startDateTime = $startDate . ' ' . $dailyStartTime . ':00';
        $endDateTime = $endDate . ' ' . $dailyEndTime . ':00';
        $conflicts = $this->inventoryService->validateFleetAvailability($items, $startDateTime, $endDateTime);
        if (!empty($conflicts)) {
            return [
                'package' => null,
                'pricing_calls' => $pricingCalls,
            ];
        }

        $package = $this->assemblePackage($selected, $pricing, $budget, $startDate, $endDate, $dailyStartTime, $dailyEndTime, $days, $durationMinutes, $campaignMode, $label, $strategy, $availableUnits);

        return [
            'package' => $package,
            'pricing_calls' => $pricingCalls,
        ];
    }

    /**
     * Try to add more assets (or more mobile units) to an under-budget package.
     */
    private function topUpPackage(array &$selected, Collection $ordered, array $availableUnits, array $unitDailyPrice, int $days, float $budget, float $finalAmount, ?array &$lastChange): bool
    {
        $utilization = $budget > 0 ? $finalAmount / $budget : 1.0;
        if ($utilization >= 1.0) {
            return false;
        }

        $room = $budget - $finalAmount;

        // Prefer bumping mobile quantities first, then adding brand-new assets.
        foreach ($ordered as $asset) {
            if (!$asset->is_mobile || !isset($selected[$asset->id])) {
                continue;
            }
            $daily = $unitDailyPrice[$asset->id] ?? PHP_FLOAT_MAX;
            $avail = $availableUnits[$asset->id] ?? 0;
            $current = $selected[$asset->id]['quantity'];
            if ($current >= $avail) {
                continue;
            }
            $extra = (int) floor($room / max(0.01, $daily * $days));
            if ($extra < 1) {
                continue;
            }
            $newQuantity = min($avail, $current + $extra);
            $selected[$asset->id]['quantity'] = $newQuantity;
            $lastChange = ['type' => 'bump', 'asset_id' => $asset->id, 'previous' => $current];
            return true;
        }

        foreach ($ordered as $asset) {
            if (isset($selected[$asset->id])) {
                continue;
            }
            $daily = $unitDailyPrice[$asset->id] ?? PHP_FLOAT_MAX;
            $avail = $availableUnits[$asset->id] ?? 0;
            $heuristicCost = $daily * $days;

            if ($asset->is_mobile) {
                $quantity = min($avail, max(1, (int) floor($room / max(0.01, $daily * $days))));
                if ($quantity < 1) {
                    continue;
                }
                $heuristicCost *= $quantity;
            } else {
                $quantity = 1;
            }

            if ($heuristicCost > $room) {
                continue;
            }

            $selected[$asset->id] = ['asset' => $asset, 'quantity' => $quantity];
            $lastChange = ['type' => 'add', 'asset_id' => $asset->id];
            return true;
        }

        return false;
    }

    /**
     * Undo the last top-up change when it pushed the package over budget.
     */
    private function revertTopUp(array &$selected, ?array $lastChange): void
    {
        if (!$lastChange) {
            return;
        }

        if ($lastChange['type'] === 'bump') {
            if (isset($selected[$lastChange['asset_id']])) {
                $selected[$lastChange['asset_id']]['quantity'] = $lastChange['previous'];
            }
        } else {
            unset($selected[$lastChange['asset_id']]);
        }
    }

    /**
     * Remove the most expensive asset (by heuristic cost) or halve a mobile
     * fleet's quantity.
     */
    private function trimPackage(array &$selected, array $unitDailyPrice, int $days): bool
    {
        if (empty($selected)) {
            return false;
        }

        $costs = [];
        foreach ($selected as $assetId => $entry) {
            $daily = $unitDailyPrice[$assetId] ?? PHP_FLOAT_MAX;
            $costs[$assetId] = $daily * $days * $entry['quantity'];
        }
        arsort($costs);
        $mostExpensiveId = array_key_first($costs);

        $entry = $selected[$mostExpensiveId];
        if ($entry['asset']->is_mobile && $entry['quantity'] > 1) {
            $selected[$mostExpensiveId]['quantity'] = max(1, (int) floor($entry['quantity'] / 2));
        } else {
            unset($selected[$mostExpensiveId]);
        }

        return !empty($selected);
    }

    /**
     * Price a candidate package through the authoritative oracle, memoized per request.
     */
    private function pricePackage(array $selected, string $startDate, string $endDate, string $dailyStartTime = '00:00', string $dailyEndTime = '23:59'): array
    {
        $screenIds = array_keys($selected);
        sort($screenIds);
        $quantities = [];
        foreach ($selected as $assetId => $entry) {
            $quantities[$assetId] = $entry['quantity'];
        }
        ksort($quantities);

        $cacheKey = implode('|', [
            implode(',', $screenIds),
            json_encode($quantities),
            $startDate,
            $endDate,
            $dailyStartTime,
            $dailyEndTime,
        ]);

        if (array_key_exists($cacheKey, $this->pricingCache)) {
            return $this->pricingCache[$cacheKey];
        }

        $result = $this->campaignPricingService->calculateCampaignPreview([
            'screen_ids' => array_keys($selected),
            'quantities' => $quantities,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_start_time' => $dailyStartTime,
            'daily_end_time' => $dailyEndTime,
        ]);

        $this->pricingCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Remove exact-duplicate packages (same asset set, quantities, dates and
     * final amount).
     */
    private function dedupePackages(array $packages): array
    {
        $seen = [];
        $unique = [];

        foreach ($packages as $package) {
            $assetIds = array_column($package['assets'], 'asset_id');
            sort($assetIds);
            $quantities = [];
            foreach ($package['assets'] as $asset) {
                $quantities[$asset['asset_id']] = $asset['quantity'];
            }
            ksort($quantities);

            $signature = implode('|', [
                implode(',', $assetIds),
                json_encode($quantities),
                $package['campaign_summary']['start_date'],
                $package['campaign_summary']['end_date'],
                $package['campaign_summary']['daily_start_time'] ?? '00:00',
                $package['campaign_summary']['daily_end_time'] ?? '23:59',
                $package['campaign_summary']['duration_minutes'] ?? 0,
                round((float) $package['pricing']['final_amount'], 2),
            ]);

            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $unique[] = $package;
        }

        return $unique;
    }

    /**
     * Assemble the response package.
     */
    private function assemblePackage(
        array $selected,
        array $pricing,
        float $budget,
        string $startDate,
        string $endDate,
        string $dailyStartTime,
        string $dailyEndTime,
        int $days,
        int $durationMinutes,
        string $campaignMode,
        string $label,
        string $strategy,
        array $availableUnits
    ): array {
        $agg = $pricing['aggregated'];
        $finalAmount = (float) $agg['final_amount'];
        $perScreen = collect($pricing['per_screen'])->keyBy('screen_id');

        $assets = [];
        $reach = 0;
        $fixedCount = 0;
        $mobileCount = 0;
        $mobileUnits = 0;
        $cities = [];
        $categories = [];

        foreach ($selected as $assetId => $entry) {
            /** @var Screen $asset */
            $asset = $entry['asset'];
            $quantity = $entry['quantity'];
            $reach += $quantity;

            if ($asset->is_mobile) {
                $mobileCount++;
                $mobileUnits += $quantity;
            } else {
                $fixedCount++;
            }

            $breakdown = $perScreen->get($assetId);
            $screenTotal = $breakdown['screen_total'] ?? [];
            $lineTotal = (float) ($screenTotal['subtotal_after_discount'] ?? 0);
            $baseTotal = (float) ($screenTotal['base_price'] ?? 0);
            $unitPricePerDay = $days > 0 && $quantity > 0 ? round($baseTotal / $quantity / $days, 2) : 0.0;

            if ($asset->city_id && !isset($cities[$asset->city_id])) {
                $cities[$asset->city_id] = [
                    'id' => $asset->city_id,
                    'name' => $asset->city?->name ?? null,
                ];
            }

            if ($asset->is_mobile) {
                foreach ($asset->coverageCities as $coverageCity) {
                    if (!isset($cities[$coverageCity->id])) {
                        $cities[$coverageCity->id] = [
                            'id' => $coverageCity->id,
                            'name' => $coverageCity->name,
                        ];
                    }
                }
            }

            if ($asset->screen_category_id && !isset($categories[$asset->screen_category_id])) {
                $categories[$asset->screen_category_id] = [
                    'id' => $asset->screen_category_id,
                    'name' => $asset->screenCategory?->name ?? null,
                ];
            }

            $assetRow = [
                'asset_id' => $asset->id,
                'name' => $asset->name,
                'asset_type' => $asset->asset_type?->value ?? AssetType::FIXED->value,
                'city_id' => $asset->city_id,
                'screen_category_id' => $asset->screen_category_id,
                'screen_category' => $asset->screenCategory ? [
                    'id' => $asset->screenCategory->id,
                    'name' => $asset->screenCategory->name,
                ] : null,
                'quantity' => $quantity,
                'pricing_plan_id' => $asset->pricing_plan_id,
                'unit_price_per_day' => $unitPricePerDay,
                'line_total' => round($lineTotal, 2),
            ];

            if ($asset->is_mobile) {
                $assetRow['fleet_size'] = $asset->fleet_size;
                $assetRow['available_units'] = $availableUnits[$asset->id] ?? 0;
            }

            $assets[] = $assetRow;
        }

        $subtotal = (float) ($agg['subtotal_before_discount'] ?? $agg['subtotal'] ?? $finalAmount)
            - (float) ($agg['screen_discount'] ?? 0)
            - (float) ($agg['duration_discount'] ?? 0);

        $budgetUtilization = $budget > 0 ? round(($finalAmount / $budget) * 100, 2) : 0.0;
        $remainingBudget = round(max(0, $budget - $finalAmount), 2);

        return [
            'recommendation_id' => $this->recommendationId($strategy, $startDate, $endDate, array_keys($selected)),
            'strategy' => $strategy,
            'label' => $label,
            'campaign_summary' => [
                'campaign_mode' => $campaignMode,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'daily_start_time' => $dailyStartTime,
                'daily_end_time' => $dailyEndTime,
                'start_datetime' => $startDate . ' ' . $dailyStartTime . ':00',
                'end_datetime' => $endDate . ' ' . $dailyEndTime . ':00',
                'duration_days' => $days,
                'duration_minutes' => $durationMinutes,
                'duration_label' => $this->formatDurationMinutes($durationMinutes),
                'total_assets' => count($assets),
                'fixed_asset_count' => $fixedCount,
                'mobile_fleet_count' => $mobileCount,
                'mobile_units_total' => $mobileUnits,
                'cities' => array_values($cities),
                'screen_categories' => array_values($categories),
            ],
            'assets' => $assets,
            'pricing' => [
                'base_price' => (float) ($agg['base_price'] ?? 0),
                'screen_discount' => (float) ($agg['screen_discount'] ?? 0),
                'duration_discount' => (float) ($agg['duration_discount'] ?? 0),
                'peak_adjustment' => (float) ($agg['peak_adjustment'] ?? 0),
                'weekend_adjustment' => (float) ($agg['weekend_adjustment'] ?? 0),
                'first_booking_discount' => (float) ($agg['first_booking_discount'] ?? 0),
                'referral_discount' => (float) ($agg['referral_discount'] ?? 0),
                'subtotal' => round(max(0, $subtotal), 2),
                'final_amount' => round($finalAmount, 2),
                'remaining_budget' => $remainingBudget,
                'budget_utilization_pct' => $budgetUtilization,
                'budget_utilization_percentage' => $budgetUtilization,
            ],
            'ranking_metrics' => [
                'reach' => $reach,
                'duration_days' => $days,
                'duration_minutes' => $durationMinutes,
                'cost_per_reach_day' => $reach > 0 && $days > 0 ? round($finalAmount / $reach / $days, 4) : 0.0,
                'budget_adherence' => $finalAmount <= $budget,
            ],
        ];
    }

    /**
     * Discovery query: active, bookable, geographically matching, category matching.
     */
    private function discoverAssets(int $countryId, array $stateIds, array $cityIds, array $categoryIds): Collection
    {
        $query = Screen::query()
            ->with(['country', 'city', 'pricingPlan', 'screenCategory', 'coverageStates', 'coverageCities'])
            ->where('country_id', $countryId)
            ->where('status', 'active')
            ->where('booking_status', 'available')
            ->whereHas('pricingPlan', function ($q) {
                $q->where('is_active', true);
            });

        if (!empty($stateIds)) {
            $query->forStates($stateIds);
        }
        if (!empty($cityIds)) {
            $query->forCities($cityIds);
        }
        if (!empty($categoryIds)) {
            $query->whereIn('screen_category_id', $categoryIds);
        }

        return $query->get();
    }

    /**
     * Resolve state ids — empty means all states of the country.
     */
    private function resolveStateIds(int $countryId, array $stateIds): array
    {
        $stateIds = array_values(array_filter(array_map('intval', $stateIds)));
        if (!empty($stateIds)) {
            return $stateIds;
        }

        return State::where('country_id', $countryId)->pluck('id')->all();
    }

    /**
     * Resolve city ids — empty means all cities of the selected states.
     */
    private function resolveCityIds(int $countryId, array $stateIds, array $cityIds): array
    {
        $cityIds = array_values(array_filter(array_map('intval', $cityIds)));
        if (!empty($cityIds)) {
            return $cityIds;
        }

        $query = City::where('country_id', $countryId);
        if (!empty($stateIds)) {
            $query->whereIn('state_id', $stateIds);
        }

        return $query->pluck('id')->all();
    }

    /**
     * Minimum campaign duration in canonical minutes for each asset's OWN pricing plan.
     */
    private function minMinutesByAsset(Collection $assets): array
    {
        $map = [];
        foreach ($assets as $asset) {
            $plan = $asset->pricingPlan;
            $map[$asset->id] = ($plan && $plan->is_active) ? $this->planMinimumMinutes($plan) : 1440;
        }

        return $map;
    }

    /**
     * Shortest legal campaign duration in canonical minutes across the given assets' plans.
     */
    private function shortestFeasibleMinutes(Collection $assets): int
    {
        $shortest = PHP_INT_MAX;
        foreach ($assets as $asset) {
            $plan = $asset->pricingPlan;
            if (!$plan || !$plan->is_active) {
                continue;
            }
            $shortest = min($shortest, $this->planMinimumMinutes($plan));
        }

        return $shortest === PHP_INT_MAX ? 1440 : $shortest;
    }

    private function planMinimumMinutes($plan): int
    {
        $value = (int) ($plan->minimum_duration ?? 1);
        $type = strtolower((string) ($plan->minimum_duration_type ?? 'day'));

        return match ($type) {
            'minute' => max(1, $value),
            'hour' => max(1, $value * 60),
            'day' => max(1, $value * 1440),
            'week' => max(1, $value * 10080),
            'month' => max(1, $value * 43200),
            default => max(1, $value * 1440),
        };
    }

    private function planMinimumDays($plan): int
    {
        $value = (int) ($plan->minimum_duration ?? 1);
        $type = strtolower((string) ($plan->minimum_duration_type ?? 'day'));

        return match ($type) {
            'minute' => max(1, (int) ceil($value / 1440)),
            'hour' => max(1, (int) ceil($value / 24)),
            'day' => max(1, $value),
            'week' => $value * 7,
            'month' => $value * 30,
            default => max(1, $value),
        };
    }

    private function ruleDays(int|float $value, ?string $unit): float
    {
        return match (strtolower((string) ($unit ?? 'day'))) {
            'minute' => $value / 1440,
            'hour' => $value / 24,
            'day' => $value,
            'week' => $value * 7,
            'month' => $value * 30,
            default => $value,
        };
    }

    private function ruleMinutes(int|float $value, ?string $unit): float
    {
        return match (strtolower((string) ($unit ?? 'day'))) {
            'minute' => $value,
            'hour' => $value * 60,
            'day' => $value * 1440,
            'week' => $value * 10080,
            'month' => $value * 43200,
            default => $value * 1440,
        };
    }

    private function formatDurationMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' Minute' . ($minutes !== 1 ? 's' : '');
        }
        if ($minutes % 1440 === 0) {
            $days = (int) ($minutes / 1440);
            return $days . ' Day' . ($days !== 1 ? 's' : '');
        }
        if ($minutes % 60 === 0) {
            $hours = (int) ($minutes / 60);
            return $hours . ' Hour' . ($hours !== 1 ? 's' : '');
        }
        $h = floor($minutes / 60);
        $m = $minutes % 60;
        return "{$h}h {$m}m";
    }

    /**
     * Heuristic per-unit daily price used ONLY for ordering.
     */
    private function heuristicUnitDailyPrices(Collection $assets): array
    {
        $ids = $assets->pluck('id')->all();
        $plans = $this->screenPricingResolver->resolveMany($ids);

        $prices = [];
        foreach ($plans as $screenId => $plan) {
            $base = (float) ($plan->base_price ?? 0);
            $daily = match (strtolower((string) ($plan->minimum_duration_type ?? 'day'))) {
                'month' => $base / 30,
                'week' => $base / 7,
                'hour' => $base * 24,
                'minute' => $base * 1440,
                default => $base,
            };
            $prices[$screenId] = max(0.01, round($daily, 4));
        }

        return $prices;
    }

    /**
     * Date-range availability per asset over the given window.
     */
    private function availableUnitsByAsset(Collection $assets, string $startDate, string $endDate, string $dailyStartTime = '00:00', string $dailyEndTime = '23:59', string $campaignMode = 'multi_day'): array
    {
        $result = [];

        $fixedIds = $assets->where('is_mobile', false)->pluck('id')->all();
        $mobileIds = $assets->where('is_mobile', true)->pluck('id')->all();

        if ($campaignMode === 'intraday') {
            $startDateTime = $startDate . ' ' . $dailyStartTime . ':00';
            $endDateTime = $endDate . ' ' . $dailyEndTime . ':00';

            if (!empty($fixedIds)) {
                foreach ($fixedIds as $id) {
                    $hasConflict = $this->inventoryService->hasFixedScreenConflict($id, $startDateTime, $endDateTime);
                    $result[$id] = $hasConflict ? 0 : 1;
                }
            }

            if (!empty($mobileIds)) {
                foreach ($mobileIds as $id) {
                    $result[$id] = $this->inventoryService->getAvailableFleet($id, $startDateTime, $endDateTime);
                }
            }

            return $result;
        }

        if (!empty($fixedIds)) {
            $conflicted = $this->fixedConflictIds($fixedIds, $startDate, $endDate);
            foreach ($fixedIds as $id) {
                $result[$id] = in_array($id, $conflicted, true) ? 0 : 1;
            }
        }

        if (!empty($mobileIds)) {
            $reserved = $this->reservedUnitsByDay($mobileIds, $startDate, $endDate);
            foreach ($mobileIds as $id) {
                $asset = $assets->firstWhere('id', $id);
                $fleet = (int) ($asset->fleet_size ?? 1);
                $maxReserved = (int) ($reserved[$id] ?? 0);
                $result[$id] = max(0, $fleet - $maxReserved);
            }
        }

        return $result;
    }

    /**
     * Fixed screens with any capacity-status booking overlapping the window.
     */
    private function fixedConflictIds(array $fixedIds, string $startDate, string $endDate): array
    {
        $campaign = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereIn('booking_screens.screen_id', $fixedIds)
            ->whereIn('bookings.status', self::CAPACITY_STATUSES)
            ->where('bookings.start_time', '<=', $endDate . ' 23:59:59')
            ->where('bookings.end_time', '>=', $startDate . ' 00:00:00')
            ->pluck('booking_screens.screen_id')
            ->all();

        $legacy = DB::table('bookings')
            ->whereIn('screen_id', $fixedIds)
            ->whereNotNull('screen_id')
            ->whereIn('status', self::CAPACITY_STATUSES)
            ->where('start_time', '<=', $endDate . ' 23:59:59')
            ->where('end_time', '>=', $startDate . ' 00:00:00')
            ->pluck('screen_id')
            ->all();

        return array_values(array_unique(array_merge($campaign, $legacy)));
    }

    /**
     * Max reserved units per mobile asset on any single day of the window.
     */
    private function reservedUnitsByDay(array $mobileIds, string $startDate, string $endDate): array
    {
        $rows = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereIn('booking_screens.screen_id', $mobileIds)
            ->whereIn('bookings.status', self::CAPACITY_STATUSES)
            ->where('bookings.start_time', '<=', $endDate . ' 23:59:59')
            ->where('bookings.end_time', '>=', $startDate . ' 00:00:00')
            ->selectRaw('booking_screens.screen_id, DATE(bookings.start_time) as day, SUM(COALESCE(booking_screens.quantity, 1)) as reserved')
            ->groupBy('booking_screens.screen_id', DB::raw('DATE(bookings.start_time)'))
            ->get();

        $maxByAsset = [];
        foreach ($rows as $row) {
            $id = (int) $row->screen_id;
            $maxByAsset[$id] = max($maxByAsset[$id] ?? 0, (int) $row->reserved);
        }

        return $maxByAsset;
    }

    private function recommendationId(string $strategy, string $startDate, string $endDate, array $screenIds): string
    {
        $hash = md5(implode('|', [$strategy, $startDate, $endDate, implode(',', $screenIds)]));

        return 'REC-' . strtoupper(substr($hash, 0, 8));
    }

    private function daysBetween(string $startDate, string $endDate): int
    {
        return Carbon::parse($startDate)->startOfDay()
            ->diffInDays(Carbon::parse($endDate)->startOfDay()) + 1;
    }

    private function emptyResponse(float $budget, string $preference, array $input, string $note, string $reason = 'NO_INVENTORY'): array
    {
        return [
            'request' => [
                'budget' => round($budget, 2),
                'country_id' => (int) ($input['country_id'] ?? 0),
                'state_ids' => $input['state_ids'] ?? [],
                'city_ids' => $input['city_ids'] ?? [],
                'screen_category_ids' => $input['screen_category_ids'] ?? [],
                'campaign_preference' => $preference,
                'campaign_mode' => $input['campaign_mode'] ?? 'multi_day',
                'start_date' => $input['start_date'] ?? null,
                'end_date' => $input['end_date'] ?? null,
                'daily_start_time' => $input['daily_start_time'] ?? null,
                'daily_end_time' => $input['daily_end_time'] ?? null,
                'duration_minutes' => $input['duration_minutes'] ?? null,
                'currency_code' => $input['currency_code'] ?? null,
                'country_code' => $input['country_code'] ?? null,
            ],
            'recommendations' => [],
            'inventory_note' => $note,
            'no_result' => [
                'reason' => $reason,
                'message' => $note,
            ],
        ];
    }

    private function buildInventoryNote(float $budget, int $validCount): string
    {
        if ($validCount === 0) {
            return "No campaign package fits within ₹" . number_format($budget) .
                " for the selected locations and categories. Try increasing the budget, widening the selected states/cities, or selecting more screen categories.";
        }

        return "Inventory constraints limited the number of feasible packages. Try increasing the budget, widening the selected states/cities, or selecting more screen categories to unlock more options.";
    }

    /**
     * Observability: log execution metrics.
     */
    private function logMetrics(array $metrics): void
    {
        try {
            Log::info('recommendations.calculate', $metrics);
        } catch (\Throwable $e) {
            // Logging must never break the recommendation response.
        }
    }
}

