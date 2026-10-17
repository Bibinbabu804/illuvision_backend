<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Http\Middleware\PermissionMiddleware;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define default system roles
        $rolesData = [
            'super-admin' => [
                'name' => 'Super Admin',
                'description' => 'Full system access. Can perform all actions and manage system settings.',
            ],
            'admin' => [
                'name' => 'Admin',
                'description' => 'Administrative access. Can manage bookings, screens, partners, and most operational areas.',
            ],
            'manager' => [
                'name' => 'Manager',
                'description' => 'Operational management. Can view and manage bookings, screens, content, and reports.',
            ],
            'staff' => [
                'name' => 'Staff',
                'description' => 'Read-only access to bookings, calendar, reports, and content listings.',
            ],
            'screen_provider' => [
                'name' => 'Screen Provider',
                'description' => 'Screen Provider Portal Access. Can monitor owned screens, campaign history, revenue, settlements, and edit profile.',
            ],
        ];

        // Define permission sections and actions to construct all possible system permissions
        $sections = [
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
            'rewards' => ['view', 'adjust', 'report', 'export'],
        ];

        // Generate all permission keys
        $allPermissions = [];
        foreach ($sections as $section => $actions) {
            foreach ($actions as $action) {
                $allPermissions[] = "{$section}.{$action}";
            }
        }

        // Define permissions assigned per role (Super Admin gets all dynamically)
        $rolePermissions = [
            'admin' => array_filter($allPermissions, function ($p) {
                // Admin gets most permissions except sensitive deletions/configurations
                return !in_array($p, [
                    'settings.delete',
                    'user_management.delete',
                    'payments.delete',
                ]);
            }),
            'manager' => [
                'dashboard.view',
                'bookings.view', 'bookings.create', 'bookings.edit',
                'screens.view', 'screens.create', 'screens.edit',
                'calendar.view',
                'pricing.view',
                'partners.view',
                'screen_providers.view',
                'products.view',
                'clients.view',
                'reports.view',
                'contacts.view',
                'blogs.view',
                'testimonials.view',
                'faqs.view',
                'jobs.view',
                'rewards.view',
            ],
            'staff' => [
                'dashboard.view',
                'bookings.view',
                'calendar.view',
                'reports.view',
                'screens.view',
                'partners.view',
                'screen_providers.view',
                'products.view',
                'contacts.view',
                'blogs.view',
                'testimonials.view',
                'faqs.view',
                'jobs.view',
                'rewards.view',
            ],
            'screen_provider' => [
                'screen_provider.dashboard',
                'screen_provider.screens',
                'screen_provider.campaigns',
                'screen_provider.revenue',
                'screen_provider.settlements',
                'screen_provider.profile',
                'screen_provider.support',
            ],
        ];

        // Seeding database roles and permissions non-destructively
        $now = now();
        foreach ($rolesData as $slug => $data) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                ]
            );

            // Clean existing permissions to recreate them accurately
            DB::table('role_permissions')->where('role_id', $role->id)->delete();

            // Set role permissions
            $perms = ($slug === 'super-admin' || $slug === 'superadmin') ? $allPermissions : ($rolePermissions[$slug] ?? []);
            
            foreach ($perms as $permission) {
                DB::table('role_permissions')->insert([
                    'role_id' => $role->id,
                    'permission' => $permission,
                    'granted' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Clear cache for the role slug
            PermissionMiddleware::clearPermissionCache($slug);
        }
    }
}
