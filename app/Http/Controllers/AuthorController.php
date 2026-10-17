<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AuthorController extends Controller
{
    /**
     * GET /api/authors
     * List all users that can be assigned as blog authors.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('perPage', $request->input('per_page', 10));

        $authorsQuery = User::select('id', 'name', 'email', 'avatar', 'phone', 'role', 'created_at');

        if ($search) {
            $authorsQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // If they pass page, paginate; otherwise return all
        if ($request->has('page')) {
            $authors = $authorsQuery->orderBy('created_at', 'desc')->paginate($perPage);
            return $this->paginatedResponse($authors, 'Authors fetched successfully');
        }

        $authors = $authorsQuery->orderBy('name', 'asc')->get();
        return $this->successResponse($authors, 'Authors fetched successfully');
    }

    /**
     * POST /api/authors
     * Create a new user who can be a blog author.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'password' => 'nullable|string|min:6',
        ]);

        $userData = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'role' => $request->role ?? 'user',
            'password' => Hash::make($request->password ?? 'password123'),
        ];

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $path = $file->store('avatars', 'public');
            $userData['avatar'] = $path;
        }

        $user = User::create($userData);

        return $this->successResponse($user, 'Author created successfully', 201);
    }

    /**
     * GET /api/authors/{id}
     * Show detailed info for a single user/author.
     */
    public function show($id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->errorResponse('Author not found', 404);
        }
        return $this->successResponse($user, 'Author fetched successfully');
    }

    /**
     * PUT or POST /api/authors/{id}
     * Update an existing user/author.
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->errorResponse('Author not found', 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $id,
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'password' => 'nullable|string|min:6',
        ]);

        $userData = $request->only(['name', 'email', 'phone', 'role']);

        if ($request->filled('password')) {
            $userData['password'] = Hash::make($request->password);
        }

        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $file = $request->file('avatar');
            $path = $file->store('avatars', 'public');
            $userData['avatar'] = $path;
        }

        $user->update($userData);

        return $this->successResponse($user, 'Author updated successfully');
    }

    /**
     * DELETE /api/authors/{id}
     * Delete an existing user/author.
     */
    public function destroy($id)
    {
        $user = User::find($id);
        if (!$user) {
            return $this->errorResponse('Author not found', 404);
        }

        // Delete avatar file if exists
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->delete();

        return $this->successResponse(null, 'Author deleted successfully');
    }
}
