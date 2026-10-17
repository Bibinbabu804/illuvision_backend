<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PermissionMiddleware;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PermissionController extends Controller
{
    private const PERMISSION_SECTIONS = [
        'dashboard' => ['view'],
        'bookings' => ['view', 'create', 'edit', 'delete', 'approve', 'export'],
        'screens' => ['view', 'create', 'edit', 'delete'],
        'calendar' => ['view'],
        'pricing' => ['view', 'create', 'edit', 'delete'],
        'partners' => ['view', 'create', 'edit', 'delete', 'approve', 'export'],
        'screen_providers' => ['view', 'create', 'edit', 'delete', 'approve', 'export'],
        'products' => ['view', 'create', 'edit', 'delete'],
        'clients' => ['view', 'create', 'edit', 'delete'],
        'payments' => ['view', 'create', 'edit', 'delete', 'approve', 'export'],
        'reports' => ['view', 'export'],
        'contacts' => ['view', 'create', 'edit', 'delete'],
        'settings' => ['view', 'edit'],
        'user_management' => ['view', 'create', 'edit', 'delete'],
        'notifications' => ['view', 'edit'],
        'blogs' => ['view', 'create', 'edit', 'delete'],
        'testimonials' => ['view', 'create', 'edit', 'delete'],
        'faqs' => ['view', 'create', 'edit', 'delete'],
        'jobs' => ['view', 'create', 'edit', 'delete'],
    ];

    /**
     * Get the permission matrix with all sections, actions, and which role has what.
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        $matrix = [];
        foreach (self::PERMISSION_SECTIONS as $section => $actions) {
            $row = [
                'section' => $section,
                'label' => ucwords(str_replace('_', ' ', $section)),
                'actions' => [],
            ];

            foreach ($actions as $action) {
                $permissionKey = "{$section}.{$action}";
                $rolePermissions = [];
                foreach ($roles as $role) {
                    $rolePermissions[$role->slug] = $role->permissions
                        ->where('permission', $permissionKey)
                        ->where('granted', true)
                        ->isNotEmpty();
                }

                $row['actions'][] = [
                    'action' => $action,
                    'label' => ucfirst($action),
                    'key' => $permissionKey,
                    'roles' => $rolePermissions,
                ];
            }

            $matrix[] = $row;
        }

        return $this->successResponse([
            'sections' => $matrix,
            'roles' => $roles->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
            ]),
        ], 'Permission matrix retrieved.');
    }

    /**
     * Get the full list of unique permission keys.
     */
    public function listKeys(): JsonResponse
    {
        $keys = [];
        foreach (self::PERMISSION_SECTIONS as $section => $actions) {
            foreach ($actions as $action) {
                $keys[] = [
                    'key' => "{$section}.{$action}",
                    'section' => $section,
                    'action' => $action,
                ];
            }
        }

        return $this->successResponse($keys, 'Permission keys retrieved.');
    }

    /**
     * Update permissions for a specific role.
     */
    public function updateRolePermissions(Request $request, Role $role): JsonResponse
    {
        if (in_array($role->slug, ['super-admin', 'superadmin'])) {
            return $this->errorResponse('Super Admin permissions cannot be modified.', 403);
        }

        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*' => 'boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed.', 422, $validator->errors());
        }

        $oldPermissions = $role->grantedPermissions();

        // Delete existing permissions and insert new ones
        $role->permissions()->delete();

        $now = now();
        foreach ($request->permissions as $permission => $granted) {
            if ($granted) {
                RolePermission::create([
                    'role_id' => $role->id,
                    'permission' => $permission,
                    'granted' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Clear permission cache for this role
        PermissionMiddleware::clearPermissionCache($role->slug);

        $role->load('permissions');

        ActivityLog::log(
            'permissions_updated',
            'role',
            $role->id,
            "Permissions updated for role {$role->name}.",
            ['permissions' => $oldPermissions],
            ['permissions' => $request->permissions]
        );

        return $this->successResponse(
            $role->grantedPermissions(),
            'Role permissions updated successfully.'
        );
    }
}
