<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Testimonial;

class TestimonialController extends Controller
{
    public function index(Request $request){
        $search = $request->search;
        $perPage = $request->per_page ?? 10;

        $query = Testimonial::query();

        if($search){
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('position', 'like', "%{$search}%")
                ->orWhere('content', 'like', "%{$search}%");
        }

        $testimonials = $query->paginate($perPage);

        return $this->paginatedResponse($testimonials, 'Testimonials fetched successfully');
    }

    public function store(Request $request){
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'image' => 'nullable|string',
            'content' => 'required|string',
            'rating' => 'nullable|numeric|min:0|max:5',
        ]);

        $testimonial = Testimonial::create($validated);

        return $this->successResponse($testimonial, 'Testimonial created successfully', 201);
    }

    public function show(Testimonial $testimonial){
        return $this->successResponse($testimonial, 'Testimonial fetched successfully');
    }

    public function update(Request $request, Testimonial $testimonial){
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'position' => 'required|string|max:255',
            'image' => 'nullable|string',
            'content' => 'required|string',
            'rating' => 'nullable|numeric|min:0|max:5',
        ]);

        $testimonial->update($validated);

        return $this->successResponse($testimonial, 'Testimonial updated successfully');
    }

    public function destroy(Testimonial $testimonial){
        $testimonial->delete();
        return $this->successResponse($testimonial, 'Testimonial deleted successfully');
    }
}
