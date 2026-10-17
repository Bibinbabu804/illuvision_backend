<?php

namespace App\Repositories\Asset;

use App\Models\Screen;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use App\Repositories\Asset\AssetRepositoryInterface;

class AssetRepository implements AssetRepositoryInterface
{
    public function findById(int $id): ?Screen
    {
        return Screen::with(['country', 'city', 'pricingPlan', 'screenProvider', 'screenCategory', 'coverageCities', 'coverageStates'])->find($id);
    }

    protected function buildFilteredQuery(array $filters = [])
    {
        $query = Screen::query();

        if (!empty($filters['asset_type'])) {
            if ($filters['asset_type'] === 'fixed') {
                $query->where(function ($q) {
                    $q->where('asset_type', 'fixed')
                      ->orWhereNull('asset_type')
                      ->orWhere('asset_type', '');
                });
            } elseif ($filters['asset_type'] === 'mobile') {
                $query->whereIn('asset_type', ['mobile', 'vehicle', 'fleet']);
            } else {
                $query->where('asset_type', $filters['asset_type']);
            }
        }

        // Screen Category Filtering (ID, 'with', 'without', 'all')
        $categoryFilter = $filters['category_filter'] ?? $filters['category_status'] ?? null;
        $screenCategoryId = $filters['screen_category_id'] ?? null;

        if ($categoryFilter === 'without' || $screenCategoryId === 'without') {
            $query->whereNull('screen_category_id');
        } elseif ($categoryFilter === 'with' || $screenCategoryId === 'with') {
            $query->whereNotNull('screen_category_id');
        } elseif (!empty($screenCategoryId) && is_numeric($screenCategoryId)) {
            $query->where('screen_category_id', (int) $screenCategoryId);
        } elseif (!empty($categoryFilter) && is_numeric($categoryFilter)) {
            $query->where('screen_category_id', (int) $categoryFilter);
        }

        // Pricing Plan Filtering (ID, 'with', 'without', 'all')
        $pricingPlanFilter = $filters['pricing_plan_filter'] ?? $filters['pricing_plan_status'] ?? null;
        $pricingPlanId = $filters['pricing_plan_id'] ?? null;

        if ($pricingPlanFilter === 'without' || $pricingPlanId === 'without') {
            $query->whereNull('pricing_plan_id');
        } elseif ($pricingPlanFilter === 'with' || $pricingPlanId === 'with') {
            $query->whereNotNull('pricing_plan_id');
        } elseif (!empty($pricingPlanId) && is_numeric($pricingPlanId)) {
            $query->where('pricing_plan_id', (int) $pricingPlanId);
        } elseif (!empty($pricingPlanFilter) && is_numeric($pricingPlanFilter)) {
            $query->where('pricing_plan_id', (int) $pricingPlanFilter);
        }

        if (!empty($filters['screen_type'])) {
            $query->where('screen_type', $filters['screen_type']);
        }

        if (!empty($filters['country_id'])) {
            $query->where('country_id', $filters['country_id']);
        }

        if (!empty($filters['state_ids'])) {
            $query->forStates((array) $filters['state_ids']);
        } elseif (!empty($filters['state_id'])) {
            $query->forState($filters['state_id']);
        }

        if (!empty($filters['city_ids'])) {
            $query->forCities((array) $filters['city_ids']);
        } elseif (!empty($filters['city_id'])) {
            $query->forCity($filters['city_id']);
        }

        if (!empty($filters['owner_type'])) {
            $query->where('owner_type', $filters['owner_type']);
        }

        if (!empty($filters['screen_provider_id'])) {
            $query->where('screen_provider_id', $filters['screen_provider_id']);
        }

        if (isset($filters['revenue_split_enabled']) && ($filters['revenue_split_enabled'] === 'true' || $filters['revenue_split_enabled'] === '1' || $filters['revenue_split_enabled'] === true)) {
            $query->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
                ->where(function ($sq) {
                    $sq->where('illuvision_percentage', '!=', 10.00)
                       ->orWhere('screen_provider_percentage', '!=', 90.00);
                });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['booking_status'])) {
            $query->where('booking_status', $filters['booking_status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('screen_code', 'like', "%{$search}%")
                  ->orWhere('location_name', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->buildFilteredQuery($filters)
            ->with(['country', 'city', 'pricingPlan', 'screenProvider', 'screenCategory', 'coverageCities', 'coverageStates']);

        $sortBy = $filters['sort_by'] ?? $filters['sortBy'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? $filters['sortOrder'] ?? 'desc');
        $allowedSorts = ['id', 'name', 'screen_code', 'created_at', 'updated_at', 'status', 'asset_type', 'location_name'];

        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        return $query->orderBy($sortBy, $sortOrder)->paginate($perPage);
    }

    public function getAll(array $filters = []): Collection
    {
        $query = $this->buildFilteredQuery($filters)
            ->with(['country', 'city', 'pricingPlan', 'screenProvider', 'screenCategory', 'coverageCities', 'coverageStates']);

        return $query->get();
    }

    public function getMapInventory(array $filters = []): Collection
    {
        $query = $this->buildFilteredQuery($filters)
            ->with([
                'city:id,name,state_id',
                'pricingPlan:id,base_price,currency,base_price_original,base_price_currency,conversion_rate_to_inr',
                'coverageCities:id,name,state_id,country_id',
                'coverageStates:id,name,country_id',
            ]);

        return $query->get();
    }

    public function getStats(array $filters = []): array
    {
        $statsFilters = $filters;
        unset($statsFilters['asset_type']);

        $baseQuery = $this->buildFilteredQuery($statsFilters);

        $total = (clone $baseQuery)->count();

        $fixedCount = (clone $baseQuery)->where(function ($q) {
            $q->where('asset_type', 'fixed')
              ->orWhereNull('asset_type')
              ->orWhere('asset_type', '');
        })->count();

        $mobileCount = (clone $baseQuery)->whereIn('asset_type', ['mobile', 'vehicle', 'fleet'])->count();

        $totalUnits = (int) (clone $baseQuery)->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(fleet_size, 1)'));

        return [
            'total' => $total,
            'fixed_count' => $fixedCount,
            'mobile_count' => $mobileCount,
            'total_units' => $totalUnits,
        ];
    }

    public function create(array $data): Screen
    {
        return Screen::create($data);
    }

    public function update(int $id, array $data): Screen
    {
        $asset = Screen::findOrFail($id);
        $asset->update($data);
        return $asset->fresh(['country', 'city', 'pricingPlan', 'screenProvider', 'coverageCities', 'coverageStates']);
    }

    public function delete(int $id): bool
    {
        $asset = Screen::findOrFail($id);
        return (bool) $asset->delete();
    }

    public function syncCoverageCities(int $assetId, array $cityData): void
    {
        $asset = Screen::findOrFail($assetId);
        $cityIds = [];
        foreach ($cityData as $item) {
            $cityId = is_array($item) ? ($item['city_id'] ?? null) : $item;
            if ($cityId) {
                $cityIds[] = (int) $cityId;
            }
        }
        $asset->coverageCities()->sync(array_unique($cityIds));
    }

    public function syncCoverageStates(int $assetId, array $stateIds): void
    {
        $asset = Screen::findOrFail($assetId);
        $asset->coverageStates()->sync($stateIds);
    }
}
