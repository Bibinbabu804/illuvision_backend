<?php

namespace App\Services\Campaign;
use App\Repositories\Asset\AssetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class AssetDiscoveryFilterService
{
    protected AssetRepositoryInterface $assetRepository;

    public function __construct(AssetRepositoryInterface $assetRepository)
    {
        $this->assetRepository = $assetRepository;
    }

    /**
     * Unified Asset Filtering for Discovery, Campaign Planner, and Price Estimator.
     *
     * Rules (Sprint C5.18):
     * - Fixed Displays: Filtered strictly by location (city.state_id and city_id).
     * - Mobile Fleets: Filtered by coverage_states (for state) and coverage_cities/coverage_locations (for city).
     */
    public function getFilteredAssets(array $filters = [], bool $paginate = false, int $perPage = 15)
    {
        if ($paginate) {
            return $this->assetRepository->getPaginated($filters, $perPage);
        }

        return $this->assetRepository->getAll($filters);
    }
}
