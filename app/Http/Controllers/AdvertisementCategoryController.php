<?php

namespace App\Http\Controllers;

use App\Models\AdvertisementCategory;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdvertisementCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = AdvertisementCategory::query();

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Active only flag (e.g. for booking flows)
        if ($request->boolean('active_only') || !$request->user()) {
            $query->where('status', 'active');
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'sort_order');
        $sortOrder = $request->input('sort_order_dir', 'asc');
        $query->orderBy($sortBy, $sortOrder)->orderBy('name', 'asc');

        // Pagination or Get all
        if ($request->has('page')) {
            $perPage = $request->integer('per_page', 10);
            $categories = $query->paginate($perPage);
            return $this->successResponse($categories, 'Advertisement categories fetched successfully');
        }

        $categories = $query->get();
        return $this->successResponse($categories, 'Advertisement categories fetched successfully');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:advertisement_categories,name',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:50',
            'status' => 'required|string|in:active,inactive',
            'sort_order' => 'required|integer',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        
        $category = AdvertisementCategory::create($validated);

        return $this->successResponse($category, 'Advertisement category created successfully', 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        $category = AdvertisementCategory::findOrFail($id);
        return $this->successResponse($category, 'Advertisement category fetched successfully');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id)
    {
        $category = AdvertisementCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('advertisement_categories', 'name')->ignore($category->id),
            ],
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:50',
            'status' => 'required|string|in:active,inactive',
            'sort_order' => 'required|integer',
        ]);

        $validated['slug'] = Str::slug($validated['name']);

        $category->update($validated);

        return $this->successResponse($category, 'Advertisement category updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id)
    {
        $category = AdvertisementCategory::findOrFail($id);

        // Check if there are any bookings using this category
        $bookingsCount = Booking::where('advertisement_category_id', $category->id)->count();

        if ($bookingsCount > 0) {
            return $this->errorResponse(
                'Cannot delete category because it is currently used by ' . $bookingsCount . ' booking(s). Please deactivate it instead.',
                422
            );
        }

        $category->delete();

        return $this->successResponse(null, 'Advertisement category deleted successfully');
    }
}
