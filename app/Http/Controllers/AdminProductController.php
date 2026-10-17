<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->search;
        $perPage = $request->per_page ?? 10;
        $sortField = $request->sort_field ?? 'display_order';
        $sortDirection = $request->sort_direction ?? 'asc';

        $query = Product::query();

        if ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('short_description', 'like', "%{$search}%");
        }

        $products = $query->orderBy($sortField, $sortDirection)
            ->paginate($perPage);

        return $this->paginatedResponse($products, 'Products fetched successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'short_description' => 'required|string',
            'ideal_for' => 'required|array',
            'ideal_for.*' => 'string',
            'thumbnail' => 'nullable|string',
            'display_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $validated['display_order'] = $validated['display_order'] ?? 0;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $product = Product::create($validated);

        return $this->successResponse($product, 'Product created successfully', 201);
    }

    public function show(Product $product)
    {
        return $this->successResponse($product, 'Product fetched successfully');
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'short_description' => 'sometimes|required|string',
            'ideal_for' => 'sometimes|required|array',
            'ideal_for.*' => 'string',
            'thumbnail' => 'nullable|string',
            'display_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if (isset($validated['name']) && $validated['name'] !== $product->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $product->update($validated);

        return $this->successResponse($product, 'Product updated successfully');
    }

    public function destroy(Product $product)
    {
        $product->delete();
        return $this->successResponse(null, 'Product deleted successfully');
    }
}
