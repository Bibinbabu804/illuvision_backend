<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(){
        $categories = Category::all();
        return $this->successResponse($categories, 'Categories fetched successfully');
    }

    public function store(Request $request){
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|string|max:255',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $category = Category::create($validated);
        return $this->successResponse($category, 'Category created successfully', 201);
    }

    public function update(Request $request, Category $category){
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|string|max:255',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $category->update($validated);
        return $this->successResponse($category, 'Category updated successfully');
    }

    public function destroy(Category $category){
        $category->delete();
        return $this->successResponse($category, 'Category deleted successfully');
    }
}
