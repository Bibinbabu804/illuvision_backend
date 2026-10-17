<?php

namespace App\Http\Controllers;

use App\Models\Achievement;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('perPage', 10);

        $achievements = Achievement::query()
            ->when($search, function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            })
            ->orderBy('order')
            ->paginate($perPage);

        return $this->paginatedResponse($achievements, 'Achievements fetched successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'image' => 'nullable|string',
            'video' => 'nullable|string',
            'order' => 'integer',
        ]);

        $achievement = Achievement::create($validated);

        return $this->successResponse($achievement, 'Achievement created successfully', 201);
    }

    public function show(Achievement $achievement)
    {
        return $this->successResponse($achievement, 'Achievement fetched successfully');
    }

    public function update(Request $request, Achievement $achievement)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'image' => 'nullable|string',
            'video' => 'nullable|string',
            'order' => 'integer',
        ]);

        $achievement->update($validated);

        return $this->successResponse($achievement, 'Achievement updated successfully');
    }

    public function destroy(Achievement $achievement)
    {
        $achievement->delete();
        return $this->successResponse($achievement, 'Achievement deleted successfully');
    }
}
