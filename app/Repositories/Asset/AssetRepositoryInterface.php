<?php

namespace App\Repositories\Asset;

use App\Models\Screen;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface AssetRepositoryInterface
{
    public function findById(int $id): ?Screen;
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function getAll(array $filters = []): Collection;
    public function getMapInventory(array $filters = []): Collection;
    public function getStats(array $filters = []): array;
    public function create(array $data): Screen;
    public function update(int $id, array $data): Screen;
    public function delete(int $id): bool;
    public function syncCoverageCities(int $assetId, array $cityData): void;
    public function syncCoverageStates(int $assetId, array $stateIds): void;
}
