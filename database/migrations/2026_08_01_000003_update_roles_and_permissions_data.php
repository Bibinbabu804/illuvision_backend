<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissionSections = [
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
    ];

    public function up(): void
    {
        // 1. Update existing role names/slugs
        DB::table('roles')->where('slug', 'operator')->update([
            'name' => 'Admin',
            'slug' => 'admin',
            'description' => 'Administrative access. Can manage bookings, screens, partners, and most operational areas.',
        ]);

        DB::table('roles')->where('slug', 'content-reviewer')->update([
            'name' => 'Manager',
            'slug' => 'manager',
            'description' => 'Operational management. Can view and manage bookings, screens, and reports.',
        ]);

        DB::table('roles')->where('slug', 'viewer')->update([
            'name' => 'Staff',
            'slug' => 'staff',
            'description' => 'Read-only access to bookings, calendar, and reports.',
        ]);

        // 2. Clear existing role permissions
        DB::table('role_permissions')->delete();

        // 3. Define permission sets per role
        $roles = DB::table('roles')->get();

        $rolePermissions = [
            'super-admin' => $this->getAllPermissions(),
            'admin' => $this->getAdminPermissions(),
            'manager' => $this->getManagerPermissions(),
            'staff' => $this->getStaffPermissions(),
        ];

        $now = now();

        foreach ($rolePermissions as $slug => $perms) {
            $role = $roles->firstWhere('slug', $slug);
            if (!$role) continue;

            foreach ($perms as $permission) {
                DB::table('role_permissions')->insert([
                    'role_id' => $role->id,
                    'permission' => $permission,
                    'granted' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Restore original role data
        DB::table('roles')->where('slug', 'admin')->update([
            'name' => 'Operator',
            'slug' => 'operator',
            'description' => 'Day-to-day operations. Manage bookings, screens, and content.',
        ]);

        DB::table('roles')->where('slug', 'manager')->update([
            'name' => 'Content Reviewer',
            'slug' => 'content-reviewer',
            'description' => 'Review and approve media content. Limited operational access.',
        ]);

        DB::table('roles')->where('slug', 'staff')->update([
            'name' => 'Viewer',
            'slug' => 'viewer',
            'description' => 'Read-only access to dashboards and reports.',
        ]);

        // Restore old permissions
        DB::table('role_permissions')->delete();

        $oldPermissions = [
            'manage_screens', 'manage_pricing', 'approve_bookings',
            'upload_media', 'view_reports', 'manage_users',
        ];

        $roles = DB::table('roles')->get();

        $oldRolePermissions = [
            'super-admin' => $oldPermissions,
            'operator' => ['manage_screens', 'approve_bookings', 'upload_media', 'view_reports'],
            'content-reviewer' => ['upload_media', 'view_reports'],
            'viewer' => ['view_reports'],
        ];

        $now = now();

        foreach ($oldRolePermissions as $slug => $perms) {
            $role = $roles->firstWhere('slug', $slug);
            if (!$role) continue;

            foreach ($perms as $perm) {
                DB::table('role_permissions')->insert([
                    'role_id' => $role->id,
                    'permission' => $perm,
                    'granted' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function getAllPermissions(): array
    {
        $perms = [];
        foreach ($this->permissionSections as $section => $actions) {
            foreach ($actions as $action) {
                $perms[] = "{$section}.{$action}";
            }
        }
        return $perms;
    }

    private function getAdminPermissions(): array
    {
        // Admin gets most permissions except some sensitive ones
        $exclude = [
            'settings.delete',
            'user_management.delete',
            'payments.delete',
        ];

        return array_filter($this->getAllPermissions(), fn($p) => !in_array($p, $exclude));
    }

    private function getManagerPermissions(): array
    {
        return [
            'dashboard.view',
            'bookings.view', 'bookings.create', 'bookings.edit',
            'screens.view', 'screens.create', 'screens.edit',
            'calendar.view',
            'clients.view',
            'reports.view',
            'products.view',
            'contacts.view',
        ];
    }

    private function getStaffPermissions(): array
    {
        return [
            'dashboard.view',
            'bookings.view',
            'calendar.view',
            'reports.view',
        ];
    }
};
