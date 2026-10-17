<?php

namespace App\Http\Controllers;

use App\Models\Screen;
use App\Models\Country;
use App\Models\State;
use App\Models\City;
use App\Models\PricingPlan;
use App\Models\PricingDurationRule;
use App\Models\ScreenDurationSplitOverride;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\AssetService;
use App\Http\Resources\AssetResource;
use App\Http\Resources\MapAssetResource;
use App\Services\ScreenDeletionService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

class ScreenController extends Controller
{
    public function __construct(
        protected AssetService $assetService,
        protected ScreenDeletionService $deletionService
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->only([
            'asset_type',
            'country_id',
            'state_id',
            'state_ids',
            'city_id',
            'city_ids',
            'status',
            'search',
            'screen_type',
            'screen_category_id',
            'category_filter',
            'category_status',
            'pricing_plan_id',
            'pricing_plan_filter',
            'pricing_plan_status',
            'booking_status',
            'owner_type',
            'screen_provider_id',
            'revenue_split_enabled',
            'sort_by',
            'sortBy',
            'sort_order',
            'sortOrder',
        ]);

        $perPage = (int) $request->input('perPage', $request->input('per_page', 10));

        $screens = $this->assetService->listAssets($filters, $perPage)->through(function ($screen) {
            return new AssetResource($screen);
        });

        $stats = $this->assetService->getAssetStats($filters);

        return $this->paginatedResponse($screens, 'Screens fetched successfully', 200, [
            'stats' => $stats,
        ]);
    }

    public function mapIndex(Request $request)
    {
        $filters = $request->only([
            'asset_type',
            'country_id',
            'state_id',
            'state_ids',
            'city_id',
            'city_ids',
            'status',
            'search',
            'screen_type',
            'screen_category_id',
            'booking_status',
            'owner_type',
        ]);

        $screens = $this->assetService->getMapInventory($filters);

        return response()->json([
            'status' => true,
            'message' => 'Map inventory fetched successfully',
            'data' => MapAssetResource::collection($screens),
            'meta' => [
                'total' => $screens->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'screen_code' => 'required|string|max:50|unique:screens,screen_code',
            'screen_type' => 'nullable|string|max:255',
            'screen_category_id' => 'required|exists:screen_categories,id',
            'asset_type' => 'nullable|string|max:50',
            'pricing_unit' => 'nullable|string|max:50',
            'fleet_size' => 'nullable|integer|min:1',
            'working_hours_pd' => 'nullable|integer|min:1|max:24',
            'travel_distance_km_pd' => 'nullable|integer|min:0',
            'display_specs' => 'nullable|string|max:255',
            'asset_metadata' => 'nullable|array',
            'country_id' => 'required|exists:countries,id',
            'city_id' => 'nullable|exists:cities,id',
            'location_name' => 'required|string|max:255',
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'owner_type' => 'sometimes|string|in:' . implode(',', array_keys(Screen::ownerTypes())),
            'screen_provider_id' => 'nullable|exists:screen_providers,id',
            'illuvision_percentage' => 'nullable|numeric|min:0|max:100',
            'screen_provider_percentage' => 'nullable|numeric|min:0|max:100',
            'duration_split_overrides' => 'nullable|array',
            'duration_split_overrides.*.pricing_duration_rule_id' => 'required|integer|exists:pricing_duration_rules,id',
            'duration_split_overrides.*.illuvision_percentage' => 'required|numeric|min:0|max:100',
            'duration_split_overrides.*.screen_provider_percentage' => 'required|numeric|min:0|max:100',
            'duration_split_overrides.*.is_active' => 'nullable|boolean',
            'status' => 'required|in:active,inactive,maintenance',
            'booking_status' => 'sometimes|required|in:available,sold_out',
            'sold_out_reason' => 'nullable|string|max:255',
            'sold_out_from' => 'nullable|date',
            'sold_out_until' => 'nullable|date',
            'admin_notes' => 'nullable|string',
            'orientation' => 'nullable|in:landscape,portrait,square',
            'resolution' => 'nullable|string|max:50',
            'operating_hours' => 'nullable|string|max:50',
            'thumbnail' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'coverage_state_ids' => 'nullable|array',
            'coverage_state_ids.*' => 'exists:states,id',
            'coverage_states' => 'nullable|array',
            'coverage_states.*' => 'exists:states,id',
            'coverage_city_ids' => 'nullable|array',
            'coverage_city_ids.*' => 'exists:cities,id',
            'coverage_cities' => 'nullable|array',
            'coverage_cities.*' => 'exists:cities,id',
        ]);

        $isMobile = ($validated['asset_type'] ?? '') === 'mobile' || ($validated['asset_type'] ?? '') === 'vehicle';
        $coverageStateIds = $request->input('coverage_state_ids', $request->input('coverage_states', []));
        $coverageCityIds = $request->input('coverage_city_ids', $request->input('coverage_cities', []));

        if ($isMobile) {
            if (empty($coverageStateIds)) {
                return $this->errorResponse('Coverage States are required for Mobile Fleets (minimum 1 state).', 422);
            }
            if (empty($coverageCityIds)) {
                return $this->errorResponse('Coverage Cities are required for Mobile Fleets (minimum 1 city).', 422);
            }
        }

        // Auto-set default owner_type if not provided
        if (!isset($validated['owner_type'])) {
            $validated['owner_type'] = Screen::OWNER_TYPE_ILLUVISION;
        }

        // Validate screen_provider_id is required when owner_type is screen_provider
        if ($validated['owner_type'] === Screen::OWNER_TYPE_PROVIDER && empty($validated['screen_provider_id'])) {
            return $this->errorResponse('Screen provider is required when owner type is Screen Provider.', 422);
        }

        // Clear screen_provider_id if owner type is not provider
        if ($validated['owner_type'] !== Screen::OWNER_TYPE_PROVIDER) {
            $validated['screen_provider_id'] = null;
        }

        $durationOverrides = $validated['duration_split_overrides'] ?? null;
        unset($validated['coverage_state_ids'], $validated['coverage_states'], $validated['coverage_city_ids'], $validated['coverage_cities'], $validated['duration_split_overrides']);

        if ($durationOverrides !== null) {
            $validationError = $this->validateDurationSplitOverrides($durationOverrides, (int) $validated['pricing_plan_id']);
            if ($validationError) {
                return $this->errorResponse($validationError, 422);
            }
        }

        $screen = DB::transaction(function () use ($validated, $coverageCityIds, $coverageStateIds, $durationOverrides) {
            $created = $this->assetService->createAsset($validated, $coverageCityIds, $coverageStateIds);
            if ($durationOverrides !== null) {
                $this->syncDurationSplitOverrides($created, (int) $created->pricing_plan_id, $durationOverrides);
            }
            return $created->load(['durationSplitOverrides', 'pricingPlan.durationRules', 'screenProvider']);
        });

        return $this->successResponse(new AssetResource($screen), 'Screen created successfully', 201);
    }

    public function show(Screen $screen)
    {
        $loadedScreen = $this->assetService->getAsset($screen->id);
        $loadedScreen->load(['durationSplitOverrides', 'pricingPlan.durationRules', 'screenProvider']);
        return $this->successResponse(new AssetResource($loadedScreen), 'Screen fetched successfully');
    }

    public function update(Request $request, Screen $screen)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'screen_code' => 'sometimes|required|string|max:50|unique:screens,screen_code,' . $screen->id,
            'screen_type' => 'nullable|string|max:255',
            'screen_category_id' => 'sometimes|required|exists:screen_categories,id',
            'asset_type' => 'nullable|string|max:50',
            'pricing_unit' => 'nullable|string|max:50',
            'fleet_size' => 'nullable|integer|min:1',
            'working_hours_pd' => 'nullable|integer|min:1|max:24',
            'travel_distance_km_pd' => 'nullable|integer|min:0',
            'display_specs' => 'nullable|string|max:255',
            'asset_metadata' => 'nullable|array',
            'country_id' => 'sometimes|required|exists:countries,id',
            'city_id' => 'nullable|exists:cities,id',
            'location_name' => 'sometimes|required|string|max:255',
            'pricing_plan_id' => 'sometimes|required|exists:pricing_plans,id',
            'owner_type' => 'sometimes|string|in:' . implode(',', array_keys(Screen::ownerTypes())),
            'screen_provider_id' => 'nullable|exists:screen_providers,id',
            'illuvision_percentage' => 'nullable|numeric|min:0|max:100',
            'screen_provider_percentage' => 'nullable|numeric|min:0|max:100',
            'duration_split_overrides' => 'nullable|array',
            'duration_split_overrides.*.pricing_duration_rule_id' => 'required|integer|exists:pricing_duration_rules,id',
            'duration_split_overrides.*.illuvision_percentage' => 'required|numeric|min:0|max:100',
            'duration_split_overrides.*.screen_provider_percentage' => 'required|numeric|min:0|max:100',
            'duration_split_overrides.*.is_active' => 'nullable|boolean',
            'status' => 'sometimes|required|in:active,inactive,maintenance',
            'booking_status' => 'sometimes|required|in:available,sold_out',
            'sold_out_reason' => 'nullable|string|max:255',
            'sold_out_from' => 'nullable|date',
            'sold_out_until' => 'nullable|date',
            'admin_notes' => 'nullable|string',
            'orientation' => 'nullable|in:landscape,portrait,square',
            'resolution' => 'nullable|string|max:50',
            'operating_hours' => 'nullable|string|max:50',
            'thumbnail' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'coverage_state_ids' => 'nullable|array',
            'coverage_state_ids.*' => 'exists:states,id',
            'coverage_states' => 'nullable|array',
            'coverage_states.*' => 'exists:states,id',
            'coverage_city_ids' => 'nullable|array',
            'coverage_city_ids.*' => 'exists:cities,id',
            'coverage_cities' => 'nullable|array',
            'coverage_cities.*' => 'exists:cities,id',
        ]);

        $effectiveAssetType = $validated['asset_type'] ?? $screen->asset_type?->value ?? $screen->asset_type ?? 'fixed';
        $isMobile = $effectiveAssetType === 'mobile' || $effectiveAssetType === 'vehicle';

        $coverageStateIds = null;
        if ($request->has('coverage_state_ids') || $request->has('coverage_states')) {
            $rawStates = $request->input('coverage_state_ids', $request->input('coverage_states', []));
            $coverageStateIds = array_unique(array_filter((array) $rawStates));
            if ($isMobile && empty($coverageStateIds)) {
                return $this->errorResponse('Coverage States are required for Mobile Fleets (minimum 1 state).', 422);
            }
        }

        $coverageCityIds = null;
        if ($request->has('coverage_city_ids') || $request->has('coverage_cities')) {
            $rawCities = $request->input('coverage_city_ids', $request->input('coverage_cities', []));
            $coverageCityIds = array_unique(array_filter((array) $rawCities));
            if ($isMobile && empty($coverageCityIds)) {
                return $this->errorResponse('Coverage Cities are required for Mobile Fleets (minimum 1 city).', 422);
            }
        }

        // Delete old thumbnail if a new one is provided
        if (!empty($validated['thumbnail']) && $screen->thumbnail && $validated['thumbnail'] !== $screen->thumbnail) {
            Storage::disk('public')->delete($screen->thumbnail);
        }

        // Validate screen_provider_id is required when owner_type is screen_provider
        if (isset($validated['owner_type'])) {
            if ($validated['owner_type'] === Screen::OWNER_TYPE_PROVIDER && empty($validated['screen_provider_id'])) {
                return $this->errorResponse('Screen provider is required when owner type is Screen Provider.', 422);
            }
            // Clear screen_provider_id if owner type is not provider
            if ($validated['owner_type'] !== Screen::OWNER_TYPE_PROVIDER) {
                $validated['screen_provider_id'] = null;
            }
        }

        $oldBookingStatus = $screen->booking_status ?? 'available';
        $oldSoldOutReason = $screen->sold_out_reason;

        $durationOverrides = $validated['duration_split_overrides'] ?? null;
        $hasDurationOverrides = $request->has('duration_split_overrides');
        $effectivePlanId = (int) ($validated['pricing_plan_id'] ?? $screen->pricing_plan_id);

        if ($hasDurationOverrides && $durationOverrides !== null) {
            $validationError = $this->validateDurationSplitOverrides($durationOverrides, $effectivePlanId);
            if ($validationError) {
                return $this->errorResponse($validationError, 422);
            }
        }

        unset($validated['coverage_state_ids'], $validated['coverage_states'], $validated['coverage_city_ids'], $validated['coverage_cities'], $validated['duration_split_overrides']);

        $updatedScreen = DB::transaction(function () use ($screen, $validated, $coverageCityIds, $coverageStateIds, $durationOverrides, $hasDurationOverrides, $effectivePlanId) {
            $res = $this->assetService->updateAsset($screen->id, $validated, $coverageCityIds, $coverageStateIds);
            if ($hasDurationOverrides) {
                $this->syncDurationSplitOverrides($res, $effectivePlanId, $durationOverrides ?? []);
            }
            return $res->load(['durationSplitOverrides', 'pricingPlan.durationRules', 'screenProvider']);
        });

        $newBookingStatus = $updatedScreen->booking_status ?? 'available';
        if ($oldBookingStatus !== $newBookingStatus) {
            if ($newBookingStatus === 'sold_out') {
                ActivityLog::log(
                    'Marked Sold Out',
                    Screen::class,
                    $updatedScreen->id,
                    "Screen '{$updatedScreen->name}' marked as Sold Out. Reason: " . ($updatedScreen->sold_out_reason ?? 'None'),
                    ['booking_status' => $oldBookingStatus, 'sold_out_reason' => $oldSoldOutReason],
                    ['booking_status' => $newBookingStatus, 'sold_out_reason' => $updatedScreen->sold_out_reason]
                );
            } else {
                ActivityLog::log(
                    'Sold Out Removed',
                    Screen::class,
                    $updatedScreen->id,
                    "Screen '{$updatedScreen->name}' marked as Available.",
                    ['booking_status' => $oldBookingStatus, 'sold_out_reason' => $oldSoldOutReason],
                    ['booking_status' => $newBookingStatus, 'sold_out_reason' => null]
                );
            }
        } elseif ($newBookingStatus === 'sold_out' && $oldSoldOutReason !== $updatedScreen->sold_out_reason) {
            ActivityLog::log(
                'Sold Out Reason Updated',
                Screen::class,
                $updatedScreen->id,
                "Screen '{$updatedScreen->name}' Sold Out reason updated to: " . ($updatedScreen->sold_out_reason ?? 'None'),
                ['sold_out_reason' => $oldSoldOutReason],
                ['sold_out_reason' => $updatedScreen->sold_out_reason]
            );
        }

        return $this->successResponse(new AssetResource($updatedScreen), 'Screen updated successfully');
    }

    /**
     * Validate duration split overrides against business rules.
     */
    protected function validateDurationSplitOverrides(array $overrides, int $pricingPlanId): ?string
    {
        if (empty($overrides)) {
            return null;
        }

        $ruleIds = [];
        foreach ($overrides as $item) {
            $illu = (float) ($item['illuvision_percentage'] ?? 0);
            $prov = (float) ($item['screen_provider_percentage'] ?? 0);

            if (abs(($illu + $prov) - 100.00) > 0.001) {
                return "Provider and Illuvision percentages must total 100%. (Found: {$illu}% + {$prov}% = " . ($illu + $prov) . "%)";
            }

            $ruleId = (int) ($item['pricing_duration_rule_id'] ?? 0);
            if (in_array($ruleId, $ruleIds, true)) {
                return "Duplicate duration override configured for duration rule #{$ruleId}.";
            }
            $ruleIds[] = $ruleId;
        }

        $durationRules = PricingDurationRule::whereIn('id', $ruleIds)->get()->keyBy('id');

        foreach ($overrides as $item) {
            $ruleId = (int) ($item['pricing_duration_rule_id'] ?? 0);
            $rule = $durationRules->get($ruleId);

            if (!$rule || (int) $rule->pricing_plan_id !== (int) $pricingPlanId) {
                return "Selected duration rule #{$ruleId} does not belong to the selected pricing plan.";
            }

            $isActive = filter_var($item['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
            if ($isActive && $rule->status !== 'active') {
                return "Cannot activate split override for inactive pricing duration rule #{$ruleId}.";
            }
        }

        return null;
    }

    /**
     * Sync duration split overrides for a screen and plan.
     */
    protected function syncDurationSplitOverrides(Screen $screen, int $pricingPlanId, array $overrides): void
    {
        $processedRuleIds = [];

        foreach ($overrides as $item) {
            $ruleId = (int) $item['pricing_duration_rule_id'];
            $processedRuleIds[] = $ruleId;

            ScreenDurationSplitOverride::updateOrCreate(
                [
                    'screen_id' => $screen->id,
                    'pricing_plan_id' => $pricingPlanId,
                    'pricing_duration_rule_id' => $ruleId,
                ],
                [
                    'illuvision_percentage' => (float) $item['illuvision_percentage'],
                    'screen_provider_percentage' => (float) $item['screen_provider_percentage'],
                    'is_active' => filter_var($item['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ]
            );
        }

        // Remove unmentioned overrides for this specific screen and pricing plan
        if (!empty($processedRuleIds)) {
            ScreenDurationSplitOverride::where('screen_id', $screen->id)
                ->where('pricing_plan_id', $pricingPlanId)
                ->whereNotIn('pricing_duration_rule_id', $processedRuleIds)
                ->delete();
        } else {
            ScreenDurationSplitOverride::where('screen_id', $screen->id)
                ->where('pricing_plan_id', $pricingPlanId)
                ->delete();
        }
    }

    public function destroy(Screen $screen)
    {
        $result = $this->deletionService->deleteScreens([$screen->id]);

        if (!$result['success']) {
            return $this->errorResponse($result['message'], 409, $result);
        }

        return $this->successResponse($screen, 'Screen deleted successfully');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'screen_ids' => 'required|array|min:1|max:1000',
            'screen_ids.*' => 'required|integer',
            'confirm_safe_only' => 'nullable|boolean',
        ]);

        $forceSafeOnly = (bool) ($validated['confirm_safe_only'] ?? false);
        $result = $this->deletionService->deleteScreens($validated['screen_ids'], $forceSafeOnly);

        if (!$result['success']) {
            return $this->errorResponse($result['message'], 409, $result);
        }

        return $this->successResponse($result, $result['message']);
    }

    /**
     * Dropdown: Get all countries (for searchable selects)
     */
    public function countries(Request $request)
    {
        $search = $request->input('search');
        // $perPage = (int) $request->input('perPage', $request->input('per_page', 500));
        $perPage = 500;
        $countries = Country::query()
            ->where('is_active', true)
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate($perPage);

        return $this->paginatedResponse($countries, 'Countries fetched successfully');
    }

    /**
     * Dropdown: Get states by country
     */
    public function statesByCountry(Request $request, Country $country)
    {
        $search = $request->input('search');
        $perPage = (int) $request->input('perPage', $request->input('per_page', 500));

        $states = State::query()
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate($perPage);

        return $this->paginatedResponse($states, 'States fetched successfully');
    }

    /**
     * Dropdown: Get cities by state
     */
    public function citiesByState(Request $request, State $state)
    {
        $search = $request->input('search');
        $perPage = (int) $request->input('perPage', $request->input('per_page', 500));

        $cities = City::query()
            ->with('state')
            ->where('state_id', $state->id)
            ->where('is_active', true)
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate($perPage);

        return $this->paginatedResponse($cities, 'Cities fetched successfully');
    }

    /**
     * Dropdown: Get cities by country (for cascading selects)
     */
    public function citiesByCountry(Request $request, Country $country)
    {
        $search = $request->input('search');
        $perPage = (int) $request->input('perPage', $request->input('per_page', 500));
        $stateIds = $request->input('state_ids');

        $cities = City::query()
            ->with('state')
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($stateIds, function ($query) use ($stateIds) {
                $ids = is_array($stateIds) ? $stateIds : explode(',', (string) $stateIds);
                $ids = array_values(array_filter(array_map('intval', $ids)));
                if (!empty($ids)) {
                    $query->whereIn('state_id', $ids);
                }
            })
            ->orderBy('name')
            ->paginate($perPage);

        return $this->paginatedResponse($cities, 'Cities fetched successfully');
    }

    /**
     * Dropdown: Quick-add a country
     */
    public function storeCountry(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:countries,name',
        ]);

        $country = Country::create([
            'name' => $validated['name'],
            'is_active' => true,
        ]);

        return $this->successResponse($country, 'Country created successfully', 201);
    }

    /**
     * Dropdown: Quick-add a state
     */
    public function storeState(Request $request)
    {
        $validated = $request->validate([
            'country_id' => 'required|exists:countries,id',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:10',
        ]);

        $state = State::create([
            'country_id' => $validated['country_id'],
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'is_active' => true,
        ]);

        return $this->successResponse($state, 'State created successfully', 201);
    }

    /**
     * Dropdown: Quick-add a city
     */
    public function storeCity(Request $request)
    {
        $validated = $request->validate([
            'country_id' => 'required|exists:countries,id',
            'state_id' => 'nullable|exists:states,id',
            'name' => 'required|string|max:255',
        ]);

        $city = City::create([
            'country_id' => $validated['country_id'],
            'state_id' => $validated['state_id'] ?? null,
            'name' => $validated['name'],
            'is_active' => true,
        ]);

        return $this->successResponse($city->load('state'), 'City created successfully', 201);
    }

    /**
     * Get screen types (static list)
     */
    public function screenTypes()
    {
        $types = [
            'Wall Mounted Display',
            'Standee Display',
            'LED Video Wall',
            'Kiosk Display',
            'Hanging Display',
            'Outdoor Billboard',
            'Indoor Digital Screen',
            'Window Facing Display',
            'Interactive Touch Display',
        ];

        return $this->successResponse($types, 'Screen types fetched successfully');
    }

    /**
     * Export all filtered assets to Excel (.xlsx/.csv) or Document (.docx/.doc).
     */
    public function export(Request $request)
    {
        $filters = $request->only([
            'asset_type',
            'country_id',
            'state_id',
            'state_ids',
            'city_id',
            'city_ids',
            'status',
            'search',
            'screen_type',
            'screen_category_id',
            'category_filter',
            'pricing_plan_id',
            'pricing_plan_filter',
            'booking_status',
            'owner_type',
            'screen_provider_id',
            'revenue_split_enabled',
            'sort_by',
            'sort_order',
        ]);

        // Enforce screen provider scoping if authenticated user is a provider
        if ($request->user() && $request->user()->screen_provider_id) {
            $filters['screen_provider_id'] = $request->user()->screen_provider_id;
        }

        $format = strtolower($request->input('format', 'xlsx'));
        $assets = $this->assetService->getAllAssets($filters);

        // Build human-readable filter summaries
        $filterSummary = [];
        if (!empty($filters['search'])) {
            $filterSummary['Search'] = $filters['search'];
        }
        if (!empty($filters['asset_type'])) {
            $filterSummary['Asset Type'] = ucfirst($filters['asset_type']);
        }
        if (!empty($filters['country_id'])) {
            $country = Country::find($filters['country_id']);
            if ($country)
                $filterSummary['Country'] = $country->name;
        }
        if (!empty($filters['state_id'])) {
            $state = State::find($filters['state_id']);
            if ($state)
                $filterSummary['State'] = $state->name;
        }
        if (!empty($filters['city_id'])) {
            $city = City::find($filters['city_id']);
            if ($city)
                $filterSummary['City'] = $city->name;
        }
        if (!empty($filters['category_filter'])) {
            if ($filters['category_filter'] === 'without') {
                $filterSummary['Category'] = 'Without Category';
            } elseif ($filters['category_filter'] === 'with') {
                $filterSummary['Category'] = 'With Category';
            } elseif (is_numeric($filters['category_filter'])) {
                $cat = \App\Models\ScreenCategory::find($filters['category_filter']);
                if ($cat)
                    $filterSummary['Category'] = $cat->name;
            }
        } elseif (!empty($filters['screen_category_id']) && is_numeric($filters['screen_category_id'])) {
            $cat = \App\Models\ScreenCategory::find($filters['screen_category_id']);
            if ($cat)
                $filterSummary['Category'] = $cat->name;
        }
        if (!empty($filters['pricing_plan_filter'])) {
            if ($filters['pricing_plan_filter'] === 'without') {
                $filterSummary['Pricing Plan'] = 'Without Pricing Plan';
            } elseif ($filters['pricing_plan_filter'] === 'with') {
                $filterSummary['Pricing Plan'] = 'With Pricing Plan';
            } elseif (is_numeric($filters['pricing_plan_filter'])) {
                $plan = PricingPlan::find($filters['pricing_plan_filter']);
                if ($plan)
                    $filterSummary['Pricing Plan'] = $plan->name;
            }
        } elseif (!empty($filters['pricing_plan_id']) && is_numeric($filters['pricing_plan_id'])) {
            $plan = PricingPlan::find($filters['pricing_plan_id']);
            if ($plan)
                $filterSummary['Pricing Plan'] = $plan->name;
        }
        if (!empty($filters['status'])) {
            $filterSummary['Status'] = ucfirst($filters['status']);
        }
        if (!empty($filters['screen_provider_id'])) {
            $provider = \App\Models\ScreenProvider::find($filters['screen_provider_id']);
            if ($provider)
                $filterSummary['Provider'] = $provider->provider_name ?: $provider->business_name;
        }

        $dateStr = date('Y-m-d');
        $locationSlug = !empty($filterSummary['City'])
            ? Str::slug($filterSummary['City'])
            : (!empty($filterSummary['State']) ? Str::slug($filterSummary['State']) : (!empty($filterSummary['Country']) ? Str::slug($filterSummary['Country']) : 'all'));

        if ($format === 'docx' || $format === 'doc' || $format === 'document') {
            return $this->exportDocument($assets, $filterSummary, "asset-management-{$locationSlug}-{$dateStr}.docx");
        }

        return $this->exportExcel($assets, $filterSummary, "asset-management-{$locationSlug}-{$dateStr}.xlsx");
    }

    protected function exportExcel($assets, array $filterSummary, string $filename)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Asset Inventory');

        // 1. Title Banner
        $sheet->mergeCells('A1:U1');
        $sheet->setCellValue('A1', 'ILLUVISION — ASSET MANAGEMENT EXPORT');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 14,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '0F172A'], // Slate 900
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        // 2. Metadata Block
        $sheet->setCellValue('A2', 'Generated Date: ' . date('Y-m-d H:i:s'));
        $sheet->setCellValue('D2', 'Total Assets: ' . count($assets));

        $filterStr = 'None (All Inventory)';
        if (!empty($filterSummary)) {
            $fList = [];
            foreach ($filterSummary as $k => $v) {
                $fList[] = "{$k}: {$v}";
            }
            $filterStr = implode(' | ', $fList);
        }
        $sheet->setCellValue('A3', 'Applied Filters: ' . $filterStr);

        $sheet->getStyle('A2:U3')->applyFromArray([
            'font' => [
                'size' => 10,
                'color' => ['rgb' => '475569'], // Slate 600
            ],
        ]);
        $sheet->getStyle('A3')->getFont()->setItalic(true);

        // 3. Table Column Headers
        $headers = [
            'Asset Code',
            'Asset Name',
            'Asset Mode',
            'Screen Category',
            'Pricing Plan',
            'Base Price',
            'Price Unit',
            'Currency',
            'Provider / Owner',
            'Illuvision Split (%)',
            'Provider Split (%)',
            'Location / Coverage',
            'City',
            'State',
            'Country',
            'Units / Capacity',
            'Status',
            'Booking Status',
            'Resolution',
            'Orientation',
            'Created At',
        ];

        $headerRow = 5;
        $sheet->fromArray($headers, null, "A{$headerRow}");
        $sheet->getStyle("A{$headerRow}:U{$headerRow}")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 10,
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E293B'], // Slate 800
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '0F172A'],
                ],
            ],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(24);

        // 4. Data Rows
        $currentRow = 6;
        foreach ($assets as $asset) {
            $isMobile = $asset->asset_type === 'mobile' || $asset->asset_type === 'vehicle' || $asset->asset_type === 'fleet';
            $unitType = $asset->pricingPlan?->minimum_duration_type ?: 'day';
            $normalizedUnit = match (strtolower(substr($unitType, 0, 3))) {
                'min' => 'min',
                'hou', 'hr' => 'hr',
                'wee', 'wk' => 'wk',
                'mon', 'mo' => 'mo',
                default => 'day',
            };

            $coverageLocation = $isMobile
                ? ($asset->coverageCities->count() > 0 ? "{$asset->coverageCities->count()} Cities Covered" : 'Mobile Fleet')
                : ($asset->location_name ?: ($asset->city?->name ? "{$asset->city->name}, {$asset->country?->name}" : '-'));

            $providerName = $asset->screenProvider
                ? ($asset->screenProvider->provider_name ?: $asset->screenProvider->business_name)
                : ($asset->owner_type === 'direct_illuvision' ? 'Illuvision Direct' : 'Internal');

            $rowData = [
                $asset->screen_code ?: "AST-{$asset->id}",
                $asset->name,
                $isMobile ? 'Mobile' : 'Fixed',
                $asset->screenCategory?->name ?: 'Uncategorized',
                $asset->pricingPlan?->name ?: 'No Plan',
                $asset->pricingPlan ? (float) $asset->pricingPlan->base_price : 0.00,
                $normalizedUnit,
                $asset->pricingPlan?->currency ?: 'INR',
                $providerName,
                (float) ($asset->illuvision_percentage ?? 10.00),
                (float) ($asset->screen_provider_percentage ?? 90.00),
                $coverageLocation,
                $asset->city?->name ?: '-',
                $asset->city?->state?->name ?: ($asset->state?->name ?: '-'),
                $asset->country?->name ?: '-',
                $isMobile ? "{$asset->fleet_size} Vehicles" : "{$asset->fleet_size} Screen",
                ucfirst($asset->status ?: 'Active'),
                ucfirst($asset->booking_status ?: 'Available'),
                $asset->display_specs['resolution'] ?? $asset->resolution ?? '1920x1080',
                ucfirst($asset->display_specs['orientation'] ?? $asset->orientation ?? 'Landscape'),
                $asset->created_at ? $asset->created_at->format('Y-m-d H:i') : '-',
            ];

            $sheet->fromArray($rowData, null, "A{$currentRow}");

            // Row striping
            if ($currentRow % 2 === 0) {
                $sheet->getStyle("A{$currentRow}:U{$currentRow}")->getFill()->applyFromArray([
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F8FAFC'], // Slate 50
                ]);
            }

            $currentRow++;
        }

        // Auto-fit all column widths
        foreach (range('A', 'U') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Freeze pane below headers
        $sheet->freezePane('A6');

        // Write to temporary file and stream response
        $tempFile = tempnam(sys_get_temp_dir(), 'illuvision_export_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ])->deleteFileAfterSend(true);
    }

    protected function exportDocument($assets, array $filterSummary, string $filename)
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Segoe UI');
        $phpWord->setDefaultFontSize(9.5);

        // Landscape A4 section with integer dimensions in twips (297mm x 210mm)
        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => 16838,
            'pageSizeH' => 11906,
            'marginTop' => 720,
            'marginRight' => 720,
            'marginBottom' => 720,
            'marginLeft' => 720,
        ]);

        // 1. Title Header
        $section->addText(
            'ILLUVISION — ASSET MANAGEMENT REPORT',
            ['bold' => true, 'size' => 16, 'color' => '0F172A']
        );
        $section->addText(
            'Digital Out-Of-Home & Mobile Fleet Inventory Overview',
            ['italic' => true, 'size' => 10, 'color' => '64748B']
        );
        $section->addTextBreak(1);

        // 2. Metadata Box
        $filterStr = 'None (All Inventory)';
        if (!empty($filterSummary)) {
            $fList = [];
            foreach ($filterSummary as $k => $v) {
                $fList[] = "{$k}: {$v}";
            }
            $filterStr = implode(' | ', $fList);
        }

        $metaTable = $section->addTable([
            'borderColor' => 'E2E8F0',
            'borderSize' => 6,
            'cellMargin' => 80,
            'bgColor' => 'F8FAFC',
        ]);
        $metaTable->addRow();
        $metaCell = $metaTable->addCell(15300);
        $textRun = $metaCell->addTextRun();
        $textRun->addText('Generated: ', ['bold' => true, 'color' => '334155']);
        $textRun->addText(date('Y-m-d H:i:s') . '    |    ', ['color' => '475569']);
        $textRun->addText('Total Assets: ', ['bold' => true, 'color' => '334155']);
        $textRun->addText(count($assets) . '    |    ', ['color' => '475569']);
        $textRun->addText('Applied Filters: ', ['bold' => true, 'color' => '334155']);
        $textRun->addText($filterStr, ['italic' => true, 'color' => '475569']);

        $section->addTextBreak(1);

        // 3. Inventory Data Table
        $tableStyle = [
            'borderColor' => 'CBD5E1',
            'borderSize' => 4,
            'cellMargin' => 70,
        ];
        $headerCellStyle = [
            'bgColor' => '1E293B',
            'valign' => 'center',
        ];
        $headerFontStyle = [
            'bold' => true,
            'color' => 'FFFFFF',
            'size' => 8.5,
        ];

        $table = $section->addTable($tableStyle);
        $table->addRow(320, ['tblHeader' => true, 'cantSplit' => true]);

        $table->addCell(1400, $headerCellStyle)->addText('Asset Code', $headerFontStyle);
        $table->addCell(2500, $headerCellStyle)->addText('Asset Name', $headerFontStyle);
        $table->addCell(1000, $headerCellStyle)->addText('Mode', $headerFontStyle);
        $table->addCell(1800, $headerCellStyle)->addText('Category', $headerFontStyle);
        $table->addCell(1800, $headerCellStyle)->addText('Pricing Plan', $headerFontStyle);
        $table->addCell(1500, $headerCellStyle)->addText('Base Rate', $headerFontStyle);
        $table->addCell(1900, $headerCellStyle)->addText('Provider / Owner', $headerFontStyle);
        $table->addCell(2200, $headerCellStyle)->addText('Location / Coverage', $headerFontStyle);
        $table->addCell(1200, $headerCellStyle)->addText('Status', $headerFontStyle);

        $rowIdx = 0;
        foreach ($assets as $asset) {
            $isMobile = $asset->asset_type === 'mobile' || $asset->asset_type === 'vehicle' || $asset->asset_type === 'fleet';
            $unitType = $asset->pricingPlan?->minimum_duration_type ?: 'day';
            $normalizedUnit = match (strtolower(substr($unitType, 0, 3))) {
                'min' => 'min',
                'hou', 'hr' => 'hr',
                'wee', 'wk' => 'wk',
                'mon', 'mo' => 'mo',
                default => 'day',
            };

            $coverageLocation = $isMobile
                ? ($asset->coverageCities->count() > 0 ? "{$asset->coverageCities->count()} Cities Covered" : 'Mobile Fleet')
                : ($asset->location_name ?: ($asset->city?->name ? "{$asset->city->name}, {$asset->country?->name}" : '-'));

            $providerName = $asset->screenProvider
                ? ($asset->screenProvider->provider_name ?: $asset->screenProvider->business_name)
                : ($asset->owner_type === 'direct_illuvision' ? 'Illuvision Direct' : 'Internal');

            $rateText = $asset->pricingPlan
                ? ($asset->pricingPlan->currency . ' ' . number_format((float) $asset->pricingPlan->base_price, 2) . '/' . $normalizedUnit)
                : '-';

            $rowBg = ($rowIdx % 2 === 1) ? ['bgColor' => 'F8FAFC'] : [];
            $cellFont = ['size' => 8, 'color' => '1E293B'];

            $cleanCode = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) ($asset->screen_code ?: "AST-{$asset->id}"));
            $cleanName = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $asset->name);
            $cleanCategory = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) ($asset->screenCategory?->name ?: 'Uncategorized'));
            $cleanPlan = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) ($asset->pricingPlan?->name ?: 'No Plan'));
            $cleanProvider = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $providerName);
            $cleanLocation = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $coverageLocation);

            $table->addRow(260);
            $table->addCell(1400, $rowBg)->addText($cleanCode, ['bold' => true, 'size' => 8, 'color' => '0F172A']);
            $table->addCell(2500, $rowBg)->addText($cleanName, $cellFont);
            $table->addCell(1000, $rowBg)->addText($isMobile ? 'Mobile' : 'Fixed', $cellFont);
            $table->addCell(1800, $rowBg)->addText($cleanCategory, $cellFont);
            $table->addCell(1800, $rowBg)->addText($cleanPlan, $cellFont);
            $table->addCell(1500, $rowBg)->addText($rateText, $cellFont);
            $table->addCell(1900, $rowBg)->addText($cleanProvider, $cellFont);
            $table->addCell(2200, $rowBg)->addText($cleanLocation, $cellFont);
            $table->addCell(1200, $rowBg)->addText(ucfirst($asset->status ?: 'Active'), ['bold' => true, 'size' => 8, 'color' => ($asset->status === 'active' ? '15803D' : '64748B')]);

            $rowIdx++;
        }

        // Save Word document to temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'illuvision_doc_') . '.docx';
        $writer = WordIOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempFile);

        return response()->download($tempFile, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ])->deleteFileAfterSend(true);
    }
}

