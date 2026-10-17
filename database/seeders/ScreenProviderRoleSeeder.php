<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScreenProviderRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create or update the screen_provider role
        $role = Role::updateOrCreate(
            ['slug' => 'screen_provider'],
            [
                'name' => 'Screen Provider',
                'description' => 'Screen Provider Portal Access. Can monitor owned screens, campaign history, revenue, settlements, and edit profile.'
            ]
        );

        // 2. Clear existing permissions for this role to avoid duplicates
        DB::table('role_permissions')->where('role_id', $role->id)->delete();

        // 3. Define granular permissions for the screen provider role
        $permissions = [
            'screen_provider.dashboard',
            'screen_provider.screens',
            'screen_provider.campaigns',
            'screen_provider.revenue',
            'screen_provider.settlements',
            'screen_provider.profile',
            'screen_provider.support',
        ];

        // 4. Seed role permissions
        $now = now();
        foreach ($permissions as $permission) {
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission' => $permission,
                'granted' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 5. Clear permissions cache for this role slug
        app(\App\Http\Middleware\PermissionMiddleware::class)->clearPermissionCache('screen_provider');
    }
}
