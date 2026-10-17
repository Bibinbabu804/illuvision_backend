<?php

namespace App\Http\Controllers;

use App\Models\ScreenCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ScreenCategoryController extends Controller
{
    /**
     * Display a listing of active screen categories.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ScreenCategory::query();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->where('status', 'active');
        }

        // Optionally restrict to categories present in specific cities/states/country
        if ($request->filled('city_ids') || $request->filled('city_id')) {
            $rawCityIds = $request->input('city_ids', $request->input('city_id'));
            $cityIds = is_array($rawCityIds) ? $rawCityIds : explode(',', (string) $rawCityIds);
            $cityIds = array_filter(array_map('intval', $cityIds));

            if (!empty($cityIds)) {
                $query->whereHas('screens', function ($q) use ($cityIds) {
                    $q->where('status', 'active')
                      ->forCities($cityIds);
                });
            }
        } elseif ($request->filled('state_ids') || $request->filled('state_id')) {
            $rawStateIds = $request->input('state_ids', $request->input('state_id'));
            $stateIds = is_array($rawStateIds) ? $rawStateIds : explode(',', (string) $rawStateIds);
            $stateIds = array_filter(array_map('intval', $stateIds));

            if (!empty($stateIds)) {
                $query->whereHas('screens', function ($q) use ($stateIds) {
                    $q->where('status', 'active')
                      ->forStates($stateIds);
                });
            }
        } elseif ($request->filled('country_id')) {
            $countryId = (int) $request->input('country_id');
            $query->whereHas('screens', function ($q) use ($countryId) {
                $q->where('status', 'active')
                  ->where('country_id', $countryId);
            });
        } elseif ($request->boolean('has_screens')) {
            $query->whereHas('screens', function ($q) {
                $q->where('status', 'active');
            });
        }

        $categories = $query->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
            'message' => 'Screen categories retrieved successfully',
        ]);
    }

    /**
     * Quick Add / Create a new screen category.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:screen_categories,name',
            'slug' => 'nullable|string|max:255|unique:screen_categories,slug',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category = ScreenCategory::create($validated);

        return response()->json([
            'success' => true,
            'data' => $category,
            'message' => 'Screen category created successfully',
        ], 201);
    }
}
