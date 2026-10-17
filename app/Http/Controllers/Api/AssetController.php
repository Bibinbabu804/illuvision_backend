<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetController extends Controller
{
    public function __construct(
        protected AssetService $assetService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only([
            'asset_type',
            'country_id',
            'city_id',
            'status',
            'search',
        ]);

        $perPage = (int) $request->get('per_page', 15);
        $assets = $this->assetService->listAssets($filters, $perPage);

        return AssetResource::collection($assets);
    }

    public function store(CreateAssetRequest $request): JsonResponse
    {
        $data = $request->validated();
        $coverageCities = $data['coverage_cities'] ?? [];
        $coverageStates = $data['coverage_states'] ?? [];
        unset($data['coverage_cities'], $data['coverage_states']);

        $asset = $this->assetService->createAsset($data, $coverageCities, $coverageStates);

        return response()->json([
            'message' => 'Asset created successfully.',
            'data' => new AssetResource($asset),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $asset = $this->assetService->getAsset($id);

        return response()->json([
            'data' => new AssetResource($asset),
        ]);
    }

    public function update(UpdateAssetRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $coverageCities = $data['coverage_cities'] ?? null;
        $coverageStates = $data['coverage_states'] ?? null;
        unset($data['coverage_cities'], $data['coverage_states']);

        $asset = $this->assetService->updateAsset($id, $data, $coverageCities, $coverageStates);

        return response()->json([
            'message' => 'Asset updated successfully.',
            'data' => new AssetResource($asset),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->assetService->deleteAsset($id);

        return response()->json([
            'message' => 'Asset deleted successfully.',
        ]);
    }
}
