<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SuccessStory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class SuccessStoryController extends Controller
{
    public function index(Request $request){
        $successStories = SuccessStory::all();
        return $this->successResponse($successStories, 'Success stories fetched successfully');
    }

    public function store(Request $request){
        // Convert empty strings to null for better validation
        $data = $request->all();
        if (isset($data['image']) && $data['image'] === '') $data['image'] = null;
        if (isset($data['video']) && $data['video'] === '') $data['video'] = null;

        Log::info('Store Success Story Data:', $data);
        
        try {
            $validator = \Validator::make($data, [
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'image' => 'required_without:video|nullable|string',
                'video' => 'required_without:image|nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed', 422, $validator->errors());
            }

            $successStory = SuccessStory::create($validator->validated());

            return $this->successResponse($successStory, 'Success story created successfully', 201);
        } catch (\Exception $e) {
            Log::error('Failed to create success story: ' . $e->getMessage());
            return $this->errorResponse('Failed to create success story', 500, $e->getMessage());
        }
    }

    public function update(Request $request, SuccessStory $successStory){
        try {
            $validated = $request->validate([
                'title' => 'nullable|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|string',
                'video' => 'nullable|string',
            ]);

            $successStory->update(array_filter($validated));

            return $this->successResponse($successStory, 'Success story updated successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update success story', 500, $e->getMessage());
        }
    }

    public function show(SuccessStory $successStory){
        return $this->successResponse($successStory, 'Success story fetched successfully');
    }

    

    public function destroy(SuccessStory $successStory){
        if ($successStory->image) {
            Storage::disk('public')->delete($successStory->image);
        }
        if ($successStory->video) {
            Storage::disk('public')->delete($successStory->video);
        }
        $successStory->delete();
        return $this->successResponse($successStory, 'Success story deleted successfully');
    }
}
