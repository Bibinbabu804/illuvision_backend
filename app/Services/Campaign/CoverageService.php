<?php

namespace App\Services\Campaign;
use App\Repositories\Asset\AssetRepositoryInterface;
use Illuminate\Support\Collection;

class CoverageService
{
    public function __construct(
        protected AssetRepositoryInterface $assetRepository
    ) {}

    public function syncCoverage(int $assetId, array $cities): void
    {
        $this->assetRepository->syncCoverageCities($assetId, $cities);
    }

    public function getAssetsByCity(int $cityId): Collection
    {
        return $this->assetRepository->getAll(['city_id' => $cityId]);
    }
}
