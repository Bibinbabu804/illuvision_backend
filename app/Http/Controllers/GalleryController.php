<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Gallery;
use Illuminate\Support\Facades\Storage;

class GalleryController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input("perPage", 20);
        $type = $request->input("type");

        $galleries = Gallery::query()

            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return $this->paginatedResponse($galleries, 'Galleries fetched successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.file_path' => 'required|string',
            'items.*.type' => 'required|string|in:image,video',
            'items.*.title' => 'nullable|string|max:255',
        ]);

        $createdItems = [];
        foreach ($validated['items'] as $item) {
            $data = [
                'title' => $item['title'] ?? null,
            ];

            if ($item['type'] === 'image') {
                $data['image'] = $item['file_path'];
            } else {
                $data['video'] = $item['file_path'];
            }

            $createdItems[] = Gallery::create($data);
        }

        return $this->successResponse($createdItems, 'Gallery created successfully', 201);
    }

    public function show(Gallery $gallery)
    {
        return $this->successResponse($gallery, 'Gallery fetched successfully');
    }

    public function update(Request $request, Gallery $gallery)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'image' => 'nullable|string',
            'video' => 'nullable|string',
        ]);

        $gallery->update($validated);

        return $this->successResponse($gallery, 'Gallery updated successfully');
    }

    public function destroy(Gallery $gallery)
    {
        if ($gallery->image) {
            Storage::disk('public')->delete($gallery->image);
        }
        if ($gallery->video) {
            Storage::disk('public')->delete($gallery->video);
        }
        $gallery->delete();
        return $this->successResponse($gallery, 'Gallery deleted successfully');
    }


}
