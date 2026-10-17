<?php

namespace App\Http\Controllers;

use App\Models\Blog;
use App\Models\BlogTag;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    /**
     * GET /api/blogs
     * Public listing with pagination, search, category/tag filtering.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page') ?? $request->input('perPage', 12);
        $search = $request->input('search');
        $categoryId = $request->input('category_id');
        $tagId = $request->input('tag_id');

        $user = auth()->user();
        if (!$user) {
            try {
                $user = auth('sanctum')->user();
            } catch (\Throwable $e) {}
        }
        if (!$user && ($token = request()->bearerToken())) {
            try {
                if (str_contains($token, '|')) {
                    [$id, $plainToken] = explode('|', $token, 2);
                    $accessToken = \Laravel\Sanctum\PersonalAccessToken::where('id', $id)->first();
                    if ($accessToken && hash_equals($accessToken->token, hash('sha256', $plainToken))) {
                        $user = $accessToken->tokenable;
                    }
                } else {
                    $accessToken = \Laravel\Sanctum\PersonalAccessToken::where('token', hash('sha256', $token))->first();
                    if ($accessToken) {
                        $user = $accessToken->tokenable;
                    }
                }
            } catch (\Throwable $e) {}
        }

        $isAdminOrStaff = ($user && in_array($user->role, ['super-admin', 'superadmin', 'admin', 'manager', 'staff', 'screen-provider', 'screen_provider']))
            || request()->bearerToken() !== null;

        $blogs = Blog::query()
            ->when(
                !$isAdminOrStaff,
                fn($query) => $query->published()
            )
            ->with(['category', 'tags', 'author:id,name'])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('short_description', 'like', "%{$search}%");
                });
            })
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->when($tagId, function ($query) use ($tagId) {
                $query->whereHas('tags', function ($q) use ($tagId) {
                    $q->where('blog_tags.id', $tagId);
                });
            })
            ->orderBy('published_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->paginatedResponse($blogs, 'Blogs fetched successfully');
    }

    /**
     * GET /api/blogs/featured
     * Returns 3 latest featured blogs.
     */
    public function featured()
    {
        $blogs = Blog::featured()
            ->with(['category', 'author:id,name'])
            ->get();

        return $this->successResponse($blogs, 'Featured blogs fetched successfully');
    }

    /**
     * GET /api/blogs/{blog}
     * Show a single blog with full details.
     */
    public function show(Blog $blog)
    {
        $user = auth()->user();
        if (!$user) {
            try {
                $user = auth('sanctum')->user();
            } catch (\Throwable $e) {}
        }
        if (!$user && ($token = request()->bearerToken())) {
            try {
                if (str_contains($token, '|')) {
                    [$id, $plainToken] = explode('|', $token, 2);
                    $accessToken = \Laravel\Sanctum\PersonalAccessToken::where('id', $id)->first();
                    if ($accessToken && hash_equals($accessToken->token, hash('sha256', $plainToken))) {
                        $user = $accessToken->tokenable;
                    }
                } else {
                    $accessToken = \Laravel\Sanctum\PersonalAccessToken::where('token', hash('sha256', $token))->first();
                    if ($accessToken) {
                        $user = $accessToken->tokenable;
                    }
                }
            } catch (\Throwable $e) {}
        }

        $isAdminOrStaff = ($user && in_array($user->role, ['super-admin', 'superadmin', 'admin', 'manager', 'staff', 'screen-provider', 'screen_provider']))
            || request()->bearerToken() !== null;

        // Only allow viewing published blogs publicly
        if (!$isAdminOrStaff && ($blog->status !== 'published' || ($blog->published_at && $blog->published_at->isFuture()))) {
            abort(404);
        }

        $blog->load(['category', 'tags', 'author:id,name', 'descriptionBlocks']);

        return $this->successResponse($blog, 'Blog fetched successfully');
    }

    /**
     * GET /api/blogs/{blog}/related
     * Returns related blogs based on shared tags/category.
     */
    public function related(Blog $blog)
    {
        $tagIds = $blog->tags()->pluck('blog_tags.id');
        $categoryId = $blog->category_id;

        $related = Blog::published()
            ->with(['category', 'author:id,name'])
            ->where('id', '!=', $blog->id)
            ->where(function ($query) use ($tagIds, $categoryId) {
                if ($tagIds->isNotEmpty()) {
                    $query->whereHas('tags', function ($q) use ($tagIds) {
                        $q->whereIn('blog_tags.id', $tagIds);
                    });
                }
                if ($categoryId) {
                    $query->orWhere('category_id', $categoryId);
                }
            })
            ->orderBy('published_at', 'desc')
            ->limit(3)
            ->get();

        return $this->successResponse($related, 'Related blogs fetched successfully');
    }

    /**
     * GET /api/blogs/categories
     * Returns all categories that have published blogs.
     */
    public function categories()
    {
        $categories = Category::whereHas('blogs', function ($query) {
            $query->published();
        })->get();

        return $this->successResponse($categories, 'Categories fetched successfully');
    }

    /**
     * GET /api/blogs/tags
     * Returns all tags that have published blogs.
     */
    public function tags()
    {
        $tags = BlogTag::whereHas('blogs', function ($query) {
            $query->published();
        })->get();

        return $this->successResponse($tags, 'Tags fetched successfully');
    }

    // ─── Admin / Authenticated methods below ─────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'resource' => 'nullable|string',
            'image_alt' => 'nullable|string',
            'banner_image' => 'nullable|string',
            'meta_title' => 'nullable|string',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string',
            'canonical_tag' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'due_date' => 'nullable|date',
            'status' => 'nullable|in:draft,published,scheduled,archived',
            'published_at' => 'nullable|date',
            'og_image' => 'nullable|string',
            'reading_time' => 'nullable|integer|min:1',
            'author_id' => 'nullable|exists:users,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:blog_tags,id',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $validated['status'] = $validated['status'] ?? 'draft';

        if (!isset($validated['published_at']) && $validated['status'] === 'published') {
            $validated['published_at'] = now();
        }

        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids']);

        $blog = Blog::create($validated);

        if (!empty($tagIds)) {
            $blog->tags()->attach($tagIds);
        }

        return $this->successResponse($blog->load(['category', 'tags', 'author:id,name']), 'Blog created successfully', 201);
    }

    public function update(Request $request, Blog $blog)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'resource' => 'nullable|string',
            'image_alt' => 'nullable|string',
            'banner_image' => 'nullable|string',
            'meta_title' => 'nullable|string',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string',
            'canonical_tag' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'due_date' => 'nullable|date',
            'status' => 'nullable|in:draft,published,scheduled,archived',
            'published_at' => 'nullable|date',
            'og_image' => 'nullable|string',
            'reading_time' => 'nullable|integer|min:1',
            'author_id' => 'nullable|exists:users,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:blog_tags,id',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        if ($request->has('banner')) {
            $validated['banner_image'] = $request->input('banner');
        }

        // Auto-stamp published_at when transitioning to published (mirrors store() logic)
        if (
            isset($validated['status']) &&
            $validated['status'] === 'published' &&
            empty($validated['published_at']) &&
            empty($blog->published_at)
        ) {
            $validated['published_at'] = now();
        }

        $tagIds = $validated['tag_ids'] ?? null;
        unset($validated['tag_ids']);

        $blog->update($validated);

        if ($tagIds !== null) {
            $blog->tags()->sync($tagIds);
        }

        return $this->successResponse(
            $blog->load(['category', 'tags', 'author:id,name', 'descriptionBlocks']),
            'Blog updated successfully'
        );
    }

    public function destroy(Blog $blog)
    {
        $blog->delete();
        return $this->successResponse(null, 'Blog deleted successfully');
    }

    public function updateCount(Blog $blog)
    {
        $blog->increment('views');
        return $this->successResponse(['views' => $blog->views], 'Blog count updated successfully');
    }
}
