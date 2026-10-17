<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\State;
use App\Services\Campaign\BudgetRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Budget Planner Recommendation Engine endpoint.
 *
 * POST /recommendations/calculate
 *
 * Public endpoint (matches /campaigns/calculate). Client provides only
 * discovery criteria — all pricing/availability data is resolved server-side
 * through the authoritative oracles.
 */
class BudgetRecommendationController extends Controller
{
    public function __construct(
        protected BudgetRecommendationService $recommendationService
    ) {}

    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Sanity limits (BP6 P0-2): generous bounds that keep legitimate
            // campaigns working while capping abusive computational payloads.
            'budget' => 'required|numeric|min:1|max:100000000',
            'country_id' => 'required|integer|exists:countries,id',
            // Empty arrays are valid: state_ids = [] means all states of the country,
            // city_ids = [] means all cities of the selected states (BP1 §41–42).
            'state_ids' => 'present|array|max:50',
            'state_ids.*' => 'integer|distinct',
            'city_ids' => 'present|array|max:200',
            'city_ids.*' => 'integer|distinct',
            'screen_category_ids' => 'nullable|array|max:30',
            'screen_category_ids.*' => 'integer|distinct',
            'campaign_preference' => 'required|string|in:more_screens,more_duration',
            'campaign_mode' => 'nullable|string|in:multi_day,intraday',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'daily_start_time' => 'nullable|string',
            'daily_end_time' => 'nullable|string',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'start_datetime' => 'nullable|date',
            'end_datetime' => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:1',
            'currency_code' => 'nullable|string|size:3',
            'country_code' => 'nullable|string|size:2',
        ]);

        // ── Date & Time validation ─────────────────────────────────────
        $campaignMode = $validated['campaign_mode'] ?? null;
        $rawStartTime = $validated['daily_start_time'] ?? $validated['start_time'] ?? null;
        $rawEndTime = $validated['daily_end_time'] ?? $validated['end_time'] ?? null;

        if ($validated['start_datetime'] ?? null) {
            $dtStart = \Carbon\Carbon::parse($validated['start_datetime']);
            $startDate = $dtStart->format('Y-m-d');
            $rawStartTime = $rawStartTime ?? $dtStart->format('H:i');
        } else {
            $startDate = $validated['start_date'] ?? now()->format('Y-m-d');
        }

        if ($validated['end_datetime'] ?? null) {
            $dtEnd = \Carbon\Carbon::parse($validated['end_datetime']);
            $endDate = $dtEnd->format('Y-m-d');
            $rawEndTime = $rawEndTime ?? $dtEnd->format('H:i');
        } else {
            $endDate = $validated['end_date'] ?? ($campaignMode === 'intraday' ? $startDate : now()->addDays(29)->format('Y-m-d'));
        }

        if (!$campaignMode) {
            $campaignMode = ($rawStartTime !== null && $rawEndTime !== null && $rawStartTime !== '00:00') ? 'intraday' : 'multi_day';
        }

        $dailyStartTime = '00:00';
        $dailyEndTime = '23:59';
        $durationMinutes = 0;

        if ($campaignMode === 'intraday') {
            $dailyStartTime = $rawStartTime ? date('H:i', strtotime($rawStartTime)) : '10:00';
            $dailyEndTime = $rawEndTime ? date('H:i', strtotime($rawEndTime)) : '10:30';

            $startTs = strtotime($startDate . ' ' . $dailyStartTime . ':00');
            $endTs = strtotime($endDate . ' ' . $dailyEndTime . ':00');

            if ($endTs <= $startTs) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed.',
                    'errors' => ['end_time' => ['end_time must be after start_time.']],
                ], 422);
            }

            $durationMinutes = (int) round(($endTs - $startTs) / 60);
        } else {
            if ($endDate < $startDate) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed.',
                    'errors' => ['end_date' => ['end_date must be on or after start_date.']],
                ], 422);
            }

            $days = \Carbon\Carbon::parse($startDate)->startOfDay()
                ->diffInDays(\Carbon\Carbon::parse($endDate)->startOfDay()) + 1;

            if ($days > 180) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed.',
                    'errors' => ['end_date' => ['The campaign window cannot exceed 180 days.']],
                ], 422);
            }

            $durationMinutes = $days * 1440;
        }

        // ── Geography integrity checks ─────────────────────────────────
        $countryId = (int) $validated['country_id'];
        $stateIds = array_values(array_filter(array_map('intval', $validated['state_ids'] ?? [])));
        $cityIds = array_values(array_filter(array_map('intval', $validated['city_ids'] ?? [])));

        $errors = [];

        if (!empty($stateIds)) {
            $validStates = State::where('country_id', $countryId)->whereIn('id', $stateIds)->pluck('id')->all();
            $invalidStates = array_diff($stateIds, $validStates);
            if (!empty($invalidStates)) {
                $errors['state_ids'] = ['One or more state_ids do not belong to the selected country.'];
            }
        }

        if (!empty($cityIds)) {
            $cityQuery = City::where('country_id', $countryId)->whereIn('id', $cityIds);
            if (!empty($stateIds)) {
                $cityQuery->whereIn('state_id', $stateIds);
            }
            $validCities = $cityQuery->pluck('id')->all();
            $invalidCities = array_diff($cityIds, $validCities);
            if (!empty($invalidCities)) {
                $errors['city_ids'] = ['One or more city_ids do not belong to the selected country/states.'];
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        // ── Solve ──────────────────────────────────────────────────────
        try {
            $result = $this->recommendationService->calculate([
                'budget' => (float) $validated['budget'],
                'country_id' => $countryId,
                'state_ids' => $stateIds,
                'city_ids' => $cityIds,
                'screen_category_ids' => $validated['screen_category_ids'] ?? [],
                'campaign_preference' => $validated['campaign_preference'],
                'campaign_mode' => $campaignMode,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'daily_start_time' => $dailyStartTime,
                'daily_end_time' => $dailyEndTime,
                'duration_minutes' => $durationMinutes,
                'currency_code' => $validated['currency_code'] ?? null,
                'country_code' => $validated['country_code'] ?? null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Campaign recommendations calculated.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('recommendations.calculate.failed', [
                'message' => $e->getMessage(),
                'country_id' => $countryId,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to calculate recommendations. Please try again.',
            ], 500);
        }
    }
}
