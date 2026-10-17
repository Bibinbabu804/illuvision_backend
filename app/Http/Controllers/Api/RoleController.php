<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Requests\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        return $this->successResponse(RoleResource::collection($roles), 'Roles retrieved');
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions');

        return $this->successResponse(new RoleResource($role), 'Role retrieved');
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $role = Role::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
        ]);

        if ($request->has('permissions')) {
            foreach ($request->permissions as $permission => $granted) {
                $role->permissions()->create([
                    'permission' => $permission,
                    'granted' => $granted ?? true,
                ]);
            }
        }

        $role->load('permissions');

        ActivityLog::log(
            'role_created',
            'role',
            $role->id,
            "Role {$role->name} was created.",
            null,
            ['name' => $role->name, 'slug' => $role->slug]
        );

        return $this->successResponse(new RoleResource($role), 'Role created', 201);
    }

    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        $oldPermissions = $role->grantedPermissions();

        $role->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        if (in_array($role->slug, ['super-admin', 'superadmin']) && $request->has('permissions')) {
            return $this->errorResponse('Super Admin permissions cannot be modified.', 403);
        }

        if ($request->has('permissions')) {
            $role->permissions()->delete();
            foreach ($request->permissions as $permission => $granted) {
                $role->permissions()->create([
                    'permission' => $permission,
                    'granted' => $granted ?? true,
                ]);
            }
        }

        // Clear permission cache
        PermissionMiddleware::clearPermissionCache($role->slug);

        $role->load('permissions');

        ActivityLog::log(
            'role_updated',
            'role',
            $role->id,
            "Role {$role->name} was updated.",
            ['permissions' => $oldPermissions],
            ['permissions' => $role->grantedPermissions()]
        );

        return $this->successResponse(new RoleResource($role), 'Role updated');
    }

    public function destroy(Role $role): JsonResponse
    {
        // Prevent deleting system roles
        if (in_array($role->slug, ['super-admin', 'admin', 'manager', 'staff'])) {
            return $this->errorResponse('System roles cannot be deleted.', 403);
        }

        $roleName = $role->name;
        $role->delete();

        PermissionMiddleware::clearPermissionCache($role->slug);

        ActivityLog::log(
            'role_deleted',
            'role',
            $role->id,
            "Role {$roleName} was deleted."
        );

        return $this->successResponse(null, 'Role deleted');
    }

    public function clone(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
        ]);

        $newRole = Role::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description ?? "Cloned from {$role->name}",
        ]);

        // Clone permissions from source role
        foreach ($role->permissions as $perm) {
            RolePermission::create([
                'role_id' => $newRole->id,
                'permission' => $perm->permission,
                'granted' => $perm->granted,
            ]);
        }

        $newRole->load('permissions');

        ActivityLog::log(
            'role_cloned',
            'role',
            $newRole->id,
            "Role {$newRole->name} was cloned from {$role->name}."
        );

        return $this->successResponse(new RoleResource($newRole), 'Role cloned successfully', 201);
    }

    public function permissions(): JsonResponse
    {
        $sections = [
            'dashboard' => ['view'],
            'bookings' => ['view', 'create', 'edit', 'delete', 'approve', 'export'],
            'screens' => ['view', 'create', 'edit', 'delete'],
            'calendar' => ['view'],
            'pricing' => ['view', 'create', 'edit', 'delete'],
            'partners' => ['view', 'create', 'edit', 'delete', 'approve', 'export'],
            'products' => ['view', 'create', 'edit', 'delete'],
            'clients' => ['view', 'create', 'edit', 'delete'],
            'payments' => ['view', 'create', 'edit', 'delete', 'approve', 'export'],
            'reports' => ['view', 'export'],
            'contacts' => ['view', 'create', 'edit', 'delete'],
            'settings' => ['view', 'edit'],
            'user_management' => ['view', 'create', 'edit', 'delete'],
            'notifications' => ['view', 'edit'],
        ];

        $result = [];
        foreach ($sections as $section => $actions) {
            foreach ($actions as $action) {
                $result[] = [
                    'key' => "{$section}.{$action}",
                    'section' => $section,
                    'action' => $action,
                    'label' => ucwords(str_replace('_', ' ', $section)) . ' - ' . ucfirst($action),
                ];
            }
        }

        return $this->successResponse($result, 'Permissions retrieved');
    }
}
