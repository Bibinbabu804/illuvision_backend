<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\JobApplication;
use Illuminate\Support\Facades\Storage;

class JobApplicationController extends Controller
{
    public function index(Request $request){
        $search = $request->search;
        $perPage = $request->per_page ?? 10;
        $job_id = $request->job_id;

        $query = JobApplication::with('job');

        if($job_id){
            $query->where('job_id', $job_id);
        }

        if($search){
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhere('message', 'like', "%{$search}%");
        }

        $jobApplications = $query->paginate($perPage);

        return $this->paginatedResponse($jobApplications, 'Job applications fetched successfully');
    }

    public function store(Request $request){
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:255',
            'resume' => 'required|file|mimes:pdf,doc,docx|max:4096',
            'job_id' => 'required|exists:job_posts,id',
        ]);
        $resume = $request->file('resume');
        
        $resumeName = time() . '_' . uniqid() . '.' . $resume->getClientOriginalExtension();
        $resumePath = $resume->storeAs('resumes', $resumeName, 'public');
        
        $jobApplication = JobApplication::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'resume' => $resumePath,
            'job_id' => $validated['job_id'],
        ]);
        
        return $this->successResponse($jobApplication, 'Job application created successfully', 201);
    }

    public function show(JobApplication $jobApplication){
        return $this->successResponse($jobApplication, 'Job application fetched successfully');
    }

    public function destroy(JobApplication $jobApplication){
        if ($jobApplication->resume) {
            Storage::disk('public')->delete($jobApplication->resume);
        }
        $jobApplication->delete();
        return $this->successResponse($jobApplication, 'Job application deleted successfully');
    }
}
