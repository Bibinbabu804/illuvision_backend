<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Services\Campaign\CoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoverageController extends Controller
{
    public function __construct(
        protected CoverageService $coverageService
    ) {}

    public function sync(Request $request, int $assetId): JsonResponse
    {
        $request->validate([
            'coverage_cities' => 'required|array',
            'coverage_cities.*.city_id' => 'required|exists:cities,id',
            'coverage_cities.*.is_primary' => 'nullable|boolean',
        ]);

        $this->coverageService->syncCoverage($assetId, $request->input('coverage_cities'));

        return response()->json([
            'message' => 'Coverage cities synced successfully.',
        ]);
    }

    public function byCity(int $cityId): JsonResponse
    {
        $assets = $this->coverageService->getAssetsByCity($cityId);

        return response()->json([
            'data' => AssetResource::collection($assets),
        ]);
    }
}
