<?php

namespace App\Http\Controllers;

use App\Models\BlogTag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BlogTagController extends Controller
{
    public function index()
    {
        $tags = BlogTag::all();
        return $this->successResponse($tags, 'Blog tags fetched successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $tag = BlogTag::create($validated);
        return $this->successResponse($tag, 'Blog tag created successfully', 201);
    }

    public function update(Request $request, BlogTag $blogTag)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $blogTag->update($validated);
        return $this->successResponse($blogTag, 'Blog tag updated successfully');
    }

    public function destroy(BlogTag $blogTag)
    {
        // Detach all blog relations before deleting
        $blogTag->blogs()->detach();
        $blogTag->delete();
        return $this->successResponse(null, 'Blog tag deleted successfully');
    }
}
