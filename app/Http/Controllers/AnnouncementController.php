<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('perPage', 10);

        $announcements = Announcement::query()
            ->when($search, function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%")
                    ->orWhere('desc', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate($perPage);

        return $this->paginatedResponse($announcements, 'Announcements fetched successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'label' => 'nullable|string|max:255',
            'date' => 'nullable|string|max:255',
            'image' => 'nullable|string',
            'desc' => 'nullable|string',
        ]);

        $announcement = Announcement::create($validated);

        return $this->successResponse($announcement, 'Announcement created successfully', 201);
    }

    public function show(Announcement $announcement)
    {
        return $this->successResponse($announcement, 'Announcement fetched successfully');
    }

    public function update(Request $request, Announcement $announcement)
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'label' => 'nullable|string|max:255',
            'date' => 'nullable|string|max:255',
            'image' => 'nullable|string',
            'desc' => 'nullable|string',
        ]);

        $announcement->update($validated);

        return $this->successResponse($announcement, 'Announcement updated successfully');
    }

    public function destroy(Announcement $announcement)
    {
        $announcement->delete();
        return $this->noContentResponse();
    }
}
