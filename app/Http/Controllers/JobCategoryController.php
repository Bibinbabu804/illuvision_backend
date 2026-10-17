<?php

namespace App\Http\Controllers;

use App\Models\JobCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class JobCategoryController extends Controller
{
    public function index(){
        $categories = JobCategory::all();
        return $this->successResponse($categories, 'Job categories fetched successfully');
    }

    public function store(Request $request){
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $category = JobCategory::create($validated);
        return $this->successResponse($category, 'Job category created successfully', 201);
    }

    public function update(Request $request, JobCategory $category){
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $category->update($validated);
        return $this->successResponse($category, 'Job category updated successfully');
    }

    public function destroy(JobCategory $category){
        $category->delete();
        return $this->successResponse($category, 'Job category deleted successfully');
    }
}
