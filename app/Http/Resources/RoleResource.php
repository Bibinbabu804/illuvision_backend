<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $permissions = [];
        foreach ($this->permissions as $perm) {
            $permissions[$perm->permission] = $perm->granted;
        }

        // Define all known permission keys
        $allPermissions = $this->getAllPermissionKeys();
        foreach ($allPermissions as $p) {
            if (!array_key_exists($p, $permissions)) {
                $permissions[$p] = false;
            }
        }

        $usersCount = User::where('role', $this->slug)->count();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'permissions' => $permissions,
            'users_count' => $usersCount,
            'is_system_role' => in_array($this->slug, ['super-admin', 'admin', 'manager', 'staff']),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function getAllPermissionKeys(): array
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

        $keys = [];
        foreach ($sections as $section => $actions) {
            foreach ($actions as $action) {
                $keys[] = "{$section}.{$action}";
            }
        }

        return $keys;
    }
}
