<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BookingCapacityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityEngineController extends Controller
{
    public function __construct(
        protected BookingCapacityService $capacityService
    ) {}

    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.asset_id' => 'required_without:items.*.screen_id|exists:screens,id',
            'items.*.screen_id' => 'required_without:items.*.asset_id|exists:screens,id',
            'items.*.quantity' => 'nullable|integer|min:1',
            'start_date' => 'required_without:start_time|string',
            'end_date' => 'required_without:end_time|string',
            'start_time' => 'sometimes|string',
            'end_time' => 'sometimes|string',
        ]);

        $start = $request->input('start_time') ?? $request->input('start_date');
        $end = $request->input('end_time') ?? $request->input('end_date');

        // Authoritative advisory calculation via BookingCapacityService
        $result = $this->capacityService->checkEligibility(
            $request->items,
            $start,
            $end
        );

        return response()->json($result->toArray(), 200);
    }
}

