<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Http\Middleware\PermissionMiddleware;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthorizationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Run the seeder to establish roles and permissions
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_super_admin_bypasses_permission_checks()
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@illuvision.net',
            'password' => bcrypt('password'),
            'role' => 'super-admin',
            'status' => 'active',
        ]);

        $this->actingAs($superAdmin);

        // Access screens.create protected endpoint
        $response = $this->postJson('/api/screens', [
            'name' => 'Test Screen',
            'screen_code' => 'SCR-TEST',
            'screen_type' => 'Wall Mounted Display',
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // It shouldn't be Forbidden (403), even if there is validation failure (422) or success, it bypasses the permission check.
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_authorized_user_can_access_permission_protected_route()
    {
        // Manager role has 'screens.view' and 'screens.create' permissions
        $manager = User::create([
            'name' => 'Manager User',
            'email' => 'manager@illuvision.net',
            'password' => bcrypt('password'),
            'role' => 'manager',
            'status' => 'active',
        ]);

        $this->actingAs($manager);

        // Access screens.create protected endpoint
        $response = $this->postJson('/api/screens', [
            'name' => 'Test Screen',
            'screen_code' => 'SCR-TEST',
            'screen_type' => 'Wall Mounted Display',
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // It shouldn't be Forbidden (403). It should be 422 (validation error) since payload is simple, or success.
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_unauthorized_user_cannot_access_permission_protected_route()
    {
        // Staff role has 'screens.view' but NOT 'screens.create'
        $staff = User::create([
            'name' => 'Staff User',
            'email' => 'staff@illuvision.net',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'status' => 'active',
        ]);

        $this->actingAs($staff);

        // Access screens.create protected endpoint
        $response = $this->postJson('/api/screens', [
            'name' => 'Test Screen',
            'screen_code' => 'SCR-TEST',
            'screen_type' => 'Wall Mounted Display',
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'status' => false,
            'message' => 'Forbidden. You do not have the required permission: screens.create',
        ]);
    }

    public function test_dynamic_permission_change_is_immediately_effective()
    {
        $staff = User::create([
            'name' => 'Staff User',
            'email' => 'staff@illuvision.net',
            'password' => bcrypt('password'),
            'role' => 'staff',
            'status' => 'active',
        ]);

        $this->actingAs($staff);

        // 1. Verify access is initially Forbidden
        $response = $this->postJson('/api/screens', [
            'name' => 'Test Screen',
            'screen_code' => 'SCR-TEST',
            'screen_type' => 'Wall Mounted Display',
            'status' => 'active',
            'booking_status' => 'available',
        ]);
        $response->assertStatus(403);

        // 2. Dynamically assign 'screens.create' to the 'staff' role
        $role = Role::where('slug', 'staff')->first();
        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission' => 'screens.create',
            'granted' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Clear cache
        PermissionMiddleware::clearPermissionCache('staff');

        // 4. Verify access is now allowed (i.e. not 403)
        $response = $this->postJson('/api/screens', [
            'name' => 'Test Screen',
            'screen_code' => 'SCR-TEST',
            'screen_type' => 'Wall Mounted Display',
            'status' => 'active',
            'booking_status' => 'available',
        ]);
        $this->assertNotEquals(403, $response->getStatusCode());
    }
}
