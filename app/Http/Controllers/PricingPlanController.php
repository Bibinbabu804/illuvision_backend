<?php

namespace App\Http\Controllers;

use App\Models\PricingPlan;
use App\Http\Requests\PricingPlanRequest;
use App\Http\Resources\PricingPlanResource;
use App\Services\PricingService;
use App\Services\BookingPriceCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PricingPlanController extends Controller
{
    protected PricingService $pricingService;
    protected BookingPriceCalculator $calculator;

    public function __construct(PricingService $pricingService, BookingPriceCalculator $calculator)
    {
        $this->pricingService = $pricingService;
        $this->calculator = $calculator;
    }

    /**
     * Display a listing of pricing plans.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $plans = PricingPlan::query()
            ->with(['durationRules', 'screenRules', 'peakRules', 'weekendRules'])
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
            })
            ->get();

        return $this->successResponse(
            PricingPlanResource::collection($plans),
            'Pricing plans fetched successfully'
        );
    }

    /**
     * Store a newly created pricing plan.
     */
    public function store(PricingPlanRequest $request): JsonResponse
    {
        $plan = $this->pricingService->store($request->validated());

        return $this->successResponse(
            new PricingPlanResource($plan),
            'Pricing plan created successfully',
            201
        );
    }

    /**
     * Display the specified pricing plan.
     */
    public function show(PricingPlan $pricingPlan): JsonResponse
    {
        $pricingPlan->load(['durationRules', 'screenRules', 'peakRules', 'weekendRules']);

        return $this->successResponse(
            new PricingPlanResource($pricingPlan),
            'Pricing plan fetched successfully'
        );
    }

    /**
     * Update the specified pricing plan.
     */
    public function update(PricingPlanRequest $request, PricingPlan $pricingPlan): JsonResponse
    {
        $plan = $this->pricingService->update($pricingPlan, $request->validated());

        return $this->successResponse(
            new PricingPlanResource($plan),
            'Pricing plan updated successfully'
        );
    }

    /**
     * Remove the specified pricing plan from storage.
     */
    public function destroy(PricingPlan $pricingPlan): JsonResponse
    {
        $pricingPlan->delete();

        return $this->successResponse(
            new PricingPlanResource($pricingPlan),
            'Pricing plan deleted successfully'
        );
    }

    /**
     * Calculate and return live preview pricing for frontend simulator.
     */
    public function preview(Request $request, ?PricingPlan $pricingPlan = null): JsonResponse
    {
        $inputs = array_merge($request->query(), $request->all());
        if ($pricingPlan) {
            $inputs['pricing_plan_id'] = $pricingPlan->id;
        }

        $validated = validator($inputs, [
            'pricing_plan_id' => 'sometimes|required_without:pricingPlanId|exists:pricing_plans,id',
            'pricingPlanId' => 'sometimes|required_without:pricing_plan_id|exists:pricing_plans,id',
            'durationHours' => 'sometimes|required_without:duration_hours|numeric|min:0.001',
            'duration_hours' => 'sometimes|required_without:durationHours|numeric|min:0.001',
            'durationUnit' => 'sometimes|string|in:Minute,Hour,Day,Week,Month,minute,hour,day,week,month',
            'duration_unit' => 'sometimes|string|in:Minute,Hour,Day,Week,Month,minute,hour,day,week,month',
            'screens' => 'sometimes|integer|min:1',
            'screen_count' => 'sometimes|integer|min:1',
            'day' => 'required|string',
            'time' => 'required|string',
        ])->validate();

        $planId = $validated['pricingPlanId'] ?? $validated['pricing_plan_id'] ?? ($pricingPlan ? $pricingPlan->id : null);
        $plan = $pricingPlan ?: PricingPlan::findOrFail($planId);

        // Normalize inputs
        $validated['duration_hours'] = $validated['duration_hours'] ?? $validated['durationHours'] ?? 1.0;
        $validated['duration_unit'] = $validated['duration_unit'] ?? $validated['durationUnit'] ?? 'Hour';
        $validated['screens'] = $validated['screens'] ?? $validated['screen_count'] ?? 1;

        $breakdown = $this->calculator->calculate($plan, $validated);

        return $this->successResponse(
            $breakdown,
            'Price preview calculated successfully'
        );
    }
}
