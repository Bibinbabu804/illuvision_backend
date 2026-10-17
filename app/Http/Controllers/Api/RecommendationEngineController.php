<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Services\Campaign\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendationEngineController extends Controller
{
    public function __construct(
        protected RecommendationService $recommendationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $cityId = $request->get('city_id') ? (int) $request->city_id : null;
        $maxBudget = $request->get('max_budget') ? (float) $request->max_budget : null;
        $assetType = $request->get('asset_type');

        $recommendations = $this->recommendationService->getRecommendations($cityId, $maxBudget, $assetType);

        return response()->json([
            'data' => AssetResource::collection($recommendations),
        ]);
    }
}
