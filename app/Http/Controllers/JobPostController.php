<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\JobPost;
use Illuminate\Support\Str;

class JobPostController extends Controller
{
    public function index(Request $request){

        $search = $request->search;
        $perPage = $request->per_page ?? 10;

        $query = JobPost::query();

        if($search){
            $query->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%")
                ->orWhere('type', 'like', "%{$search}%")
                ->orWhere('company', 'like', "%{$search}%");
        }

        $jobPosts = $query->paginate($perPage);

        return $this->paginatedResponse($jobPosts, 'Job posts fetched successfully');
        
    }

    public function store(Request $request){
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'location' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:job_categories,id',
            'due_date' => 'nullable'
        ]);

        $validated['slug'] = Str::slug($validated['title']);
        $jobPost = JobPost::create($validated);
        return $this->successResponse($jobPost, 'Job post created successfully', 201);
    }

    public function show(JobPost $jobPost){
        return $this->successResponse($jobPost, 'Job post fetched successfully');
    }

    public function update(Request $request, JobPost $jobPost){
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'location' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:job_categories,id',
            'due_date' => 'nullable'
        ]);

        $validated['slug'] = Str::slug($validated['title']);
        $jobPost->update($validated);
        return $this->successResponse($jobPost, 'Job post updated successfully');
    }

    public function destroy(JobPost $jobPost){
        $jobPost->delete();
        return $this->successResponse($jobPost, 'Job post deleted successfully');
    }
}
