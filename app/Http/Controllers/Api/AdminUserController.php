<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return $this->paginatedResponse($users, 'Admin users retrieved successfully.');
    }

    public function show(User $user): JsonResponse
    {
        return $this->successResponse(
            new AdminUserResource($user),
            'Admin user retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'role' => ['required', 'string', Rule::in(Role::pluck('slug')->toArray())],
            'status' => 'nullable|string|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 422, $validator->errors());
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => $request->role,
            'status' => $request->status ?? 'active',
        ]);

        ActivityLog::log(
            'user_created',
            'user',
            $user->id,
            "Admin user {$user->name} was created with role {$request->role}.",
            null,
            [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
            ]
        );

        return $this->successResponse(
            new AdminUserResource($user),
            'Admin user created successfully.',
            201
        );
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => 'nullable|string|max:20',
            'role' => ['sometimes', 'string', Rule::in(Role::pluck('slug')->toArray())],
            'status' => 'nullable|string|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 422, $validator->errors());
        }

        $oldValues = $user->only(['name', 'email', 'phone', 'role', 'status']);

        $user->fill($request->only(['name', 'email', 'phone', 'role', 'status']));

        if ($user->isDirty()) {
            $user->save();

            ActivityLog::log(
                'user_updated',
                'user',
                $user->id,
                "Admin user {$user->name} was updated.",
                $oldValues,
                $user->only(['name', 'email', 'phone', 'role', 'status'])
            );
        }

        return $this->successResponse(
            new AdminUserResource($user->fresh()),
            'Admin user updated successfully.'
        );
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 422, $validator->errors());
        }

        $oldStatus = $user->status;
        $user->status = $request->status;
        $user->save();

        ActivityLog::log(
            $request->status === 'active' ? 'user_activated' : ($request->status === 'suspended' ? 'user_suspended' : 'user_deactivated'),
            'user',
            $user->id,
            "Admin user {$user->name} status changed from {$oldStatus} to {$request->status}.",
            ['status' => $oldStatus],
            ['status' => $request->status]
        );

        return $this->successResponse(
            new AdminUserResource($user),
            "User status updated to {$request->status}."
        );
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 422, $validator->errors());
        }

        $user->password = Hash::make($request->password);
        $user->save();

        ActivityLog::log(
            'password_reset',
            'user',
            $user->id,
            "Password was reset for admin user {$user->name}.",
        );

        return $this->successResponse(null, 'Password reset successfully.');
    }

    public function activity(User $user, Request $request): JsonResponse
    {
        $activities = ActivityLog::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return $this->paginatedResponse($activities, 'Activity log retrieved.');
    }
}
