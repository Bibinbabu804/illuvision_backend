<?php

namespace App\Http\Controllers\Api;

use App\Application\Pricing\Adapters\LegacyPricingEngineAdapter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingEngineController extends Controller
{
    public function __construct(
        protected LegacyPricingEngineAdapter $legacyPricingEngineAdapter
    ) {}

    public function calculate(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.asset_id' => 'required|exists:screens,id',
            'items.*.quantity' => 'nullable|integer|min:1',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $result = $this->legacyPricingEngineAdapter->calculatePreview($request->all());

        return response()->json([
            'data' => $result,
        ]);
    }
}
