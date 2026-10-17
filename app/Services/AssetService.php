<?php

namespace App\Services;

use App\Models\Screen;
use App\Services\Campaign\AssetDiscoveryFilterService;
use App\Services\Campaign\CoverageService;
use App\Repositories\Asset\AssetRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class AssetService
{
    public function __construct(
        protected AssetRepositoryInterface $assetRepository,
        protected CoverageService $coverageService,
        protected AssetDiscoveryFilterService $discoveryFilterService
    ) {}

    public function getAsset(int $id): Screen
    {
        $asset = $this->assetRepository->findById($id);
        if (!$asset) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Asset #{$id} not found.");
        }
        return $asset;
    }

    public function listAssets(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->discoveryFilterService->getFilteredAssets($filters, true, $perPage);
    }

    public function getAllAssets(array $filters = []): Collection
    {
        return $this->discoveryFilterService->getFilteredAssets($filters, false);
    }

    public function getMapInventory(array $filters = []): Collection
    {
        return $this->assetRepository->getMapInventory($filters);
    }

    public function getAssetStats(array $filters = []): array
    {
        return $this->assetRepository->getStats($filters);
    }

    public function createAsset(array $data, array $coverageCities = [], array $coverageStates = []): Screen
    {
        // Default asset_code if not provided
        if (empty($data['screen_code'])) {
            $data['screen_code'] = 'AST-' . strtoupper(substr(md5(uniqid()), 0, 6));
        }

        $asset = $this->assetRepository->create($data);

        if (!empty($coverageCities)) {
            $this->coverageService->syncCoverage($asset->id, $coverageCities);
        }

        if (!empty($coverageStates)) {
            $this->assetRepository->syncCoverageStates($asset->id, $coverageStates);
        }

        return $asset->fresh(['country', 'city', 'pricingPlan', 'screenProvider', 'coverageCities', 'coverageStates']);
    }

    public function updateAsset(int $id, array $data, ?array $coverageCities = null, ?array $coverageStates = null): Screen
    {
        $asset = $this->assetRepository->update($id, $data);

        if ($coverageCities !== null) {
            $this->coverageService->syncCoverage($asset->id, $coverageCities);
        }

        if ($coverageStates !== null) {
            $this->assetRepository->syncCoverageStates($asset->id, $coverageStates);
        }

        return $asset->fresh(['country', 'city', 'pricingPlan', 'screenProvider', 'coverageCities', 'coverageStates']);
    }

    public function deleteAsset(int $id): bool
    {
        return $this->assetRepository->delete($id);
    }
}
