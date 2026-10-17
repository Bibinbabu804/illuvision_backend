<?php

namespace Tests\Feature\Security;

use App\Models\Partner;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-03 — Roles & Partners authorization.
 *
 * Policy (from original request, with existing vocabulary):
 * - Roles: administration of roles/permissions should REUSE user_management.* (not invent roles.*).
 *          AdminUserController::update already changes user roles behind user_management.edit.
 * - Partners: use partners.view/create/edit/delete/approve. partners.view already granted to manager/staff.
 * Routes currently only have auth:sanctum — add minimal permission checks using existing permissions.
 */
class Sec03RolePartnerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst(str_replace('-', ' ', $role)),
            'email' => "sec03-$role-" . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function partner(): Partner
    {
        return Partner::create([
            'name' => 'Sec03 Partner',
            'contact_person' => 'Sec03 Contact',
            'email' => 'sec03-partner-' . uniqid() . '@example.test',
            'phone' => '+91-9000000000',
            'city' => 'Pune',
            'profession' => 'Advertising',
            'company' => 'Sec03 Co',
            'message' => 'seeded by Sec03RolePartnerAuthorizationTest',
            'status' => Partner::STATUS_PENDING,
        ]);
    }

    private function role(): Role
    {
        return Role::firstOrCreate(
            ['slug' => 'sec03-target-role'],
            ['name' => 'Sec03 Target Role', 'description' => 'route binding target']
        );
    }

    /**
     * SubstituteBindings lives in the `api` middleware group, which is expanded BEFORE
     * any route-level middleware. So a missing model yields 404 before PermissionMiddleware
     * is ever reached. Every test below therefore uses REAL records, so the assertion
     * measures the permission check and never the binding.
     */
    private function resolve(string $uri): string
    {
        return str_replace(
            ['ROLE', 'PARTNER'],
            [(string) $this->role()->id, (string) $this->partner()->id],
            $uri
        );
    }

    public static function roleRoutes(): array
    {
        return [
            ['GET',    '/api/roles',                   'user_management.view', false],
            ['POST',   '/api/roles',                   'user_management.edit', true],
            ['GET',    '/api/roles/ROLE',              'user_management.view', false],
            ['PUT',    '/api/roles/ROLE',              'user_management.edit', true],
            ['PATCH',  '/api/roles/ROLE',              'user_management.edit', true],
            ['DELETE', '/api/roles/ROLE',              'user_management.edit', true],
            ['POST',   '/api/roles/ROLE/clone',        'user_management.edit', true],
            ['PUT',    '/api/roles/ROLE/permissions',  'user_management.edit', true],
            ['GET',    '/api/permissions',             'user_management.view', false],
            ['GET',    '/api/permissions/keys',        'user_management.view', false],
        ];
    }

    public static function partnerRoutes(): array
    {
        return [
            ['GET',    '/api/partners',                       'partners.view'],
            ['GET',    '/api/partners/all',                   'partners.view'],
            ['GET',    '/api/partners/kpis',                  'partners.view'],
            ['POST',   '/api/partners',                       'partners.create'],
            ['POST',   '/api/partners/create-with-onboarding','partners.create'],
            ['GET',    '/api/partners/PARTNER',               'partners.view'],
            ['PUT',    '/api/partners/PARTNER',               'partners.edit'],
            ['DELETE', '/api/partners/PARTNER',               'partners.delete'],
            ['POST',   '/api/partners/PARTNER/approve',       'partners.approve'],
            ['POST',   '/api/partners/PARTNER/reject',        'partners.approve'],
            ['POST',   '/api/partners/PARTNER/mark-under-review','partners.edit'],
            ['PATCH',  '/api/partners/PARTNER/status',        'partners.edit'],
            ['GET',    '/api/partners/PARTNER/timeline',      'partners.view'],
            ['GET',    '/api/partners/PARTNER/bookings',      'partners.view'],
            ['GET',    '/api/partners/PARTNER/commissions',   'partners.view'],
        ];
    }

    /** @dataProvider roleRoutes */
    public function test_role_route_denies_anonymous_and_unpermissioned_and_allows_admin(
        string $method, string $uri, string $permission, bool $mutates
    ) {
        $uri = $this->resolve($uri);

        // 1. anonymous -> 401
        $this->json($method, $uri)->assertStatus(401);

        // 2. authenticated with no permissions -> 403
        $this->actingAs($this->user('client'))->json($method, $uri, [])
            ->assertStatus(403);

        // 3. 'staff' holds user_management.view? No. It is read-only on bookings/content
        //    only, so every role route must be refused for staff.
        $this->actingAs($this->user('staff'))->json($method, $uri, [])
            ->assertStatus(403);

        // 4. 'admin' holds user_management.* (it is granted every permission except
        //    settings.delete / user_management.delete / payments.delete), so admin must
        //    get PAST the permission middleware. It may still 4xx on payload/record
        //    grounds; what matters is that it is not blocked by authorization.
        //
        //    NOTE: PUT /roles/{role}/permissions DELETES and rewrites the role's
        //    permission rows. The target here is this test's own throwaway role, never a
        //    seeded role, so shared authorization state is never corrupted. The body is
        //    left invalid so the endpoint validates instead of mutating.
        $res = $this->actingAs($this->user('admin'))->json($method, $uri, []);
        $this->assertNotContains(
            $res->getStatusCode(),
            [401, 403],
            "SEC-03 REGRESSION: an admin holding {$permission} was denied on "
            . "{$method} {$uri} with {$res->getStatusCode()}."
        );

        // 5. super-admin bypass must always pass authorization
        $res = $this->actingAs($this->user('super-admin'))->json($method, $uri, []);
        $this->assertNotContains(
            $res->getStatusCode(),
            [401, 403],
            "SEC-03 REGRESSION: super-admin was denied on {$method} {$uri} with "
            . "{$res->getStatusCode()}."
        );
    }

    /** @dataProvider partnerRoutes */
    public function test_partner_route_denies_anonymous_and_unpermissioned_and_allows_admin(
        string $method, string $uri, string $permission
    ) {
        $uri = $this->resolve($uri);

        // 1. anonymous -> 401
        $this->json($method, $uri)->assertStatus(401);

        // 2. authenticated with no permissions -> 403
        $this->actingAs($this->user('client'))->json($method, $uri, [])
            ->assertStatus(403);

        // 3. admin holds partners.* -> must pass the permission middleware
        $res = $this->actingAs($this->user('admin'))->json($method, $uri, []);
        $this->assertNotContains(
            $res->getStatusCode(),
            [401, 403],
            "SEC-03 REGRESSION: an admin holding {$permission} was denied on "
            . "{$method} {$uri} with {$res->getStatusCode()}."
        );

        // 4. super-admin bypass
        $res = $this->actingAs($this->user('super-admin'))->json($method, $uri, []);
        $this->assertNotContains(
            $res->getStatusCode(),
            [401, 403],
            "SEC-03 REGRESSION: super-admin was denied on {$method} {$uri} with "
            . "{$res->getStatusCode()}."
        );
    }

    public function test_partner_reads_remain_available_to_manager_and_staff()
    {
        // partners.view is granted to manager AND staff. Gating the read routes must NOT
        // remove access for the roles that already had it, otherwise this would be an
        // unintended behaviour change rather than a security fix.
        $partnerId = (string) $this->partner()->id;

        foreach (['manager', 'staff'] as $role) {
            $this->actingAs($this->user($role))
                ->getJson("/api/partners/{$partnerId}")
                ->assertStatus(200);
        }
    }

    public function test_partner_approval_requires_approve_not_just_edit()
    {
        // A role holding partners.edit but not partners.approve must still be unable to
        // approve a partner. staff holds neither; admin holds both. This is asserted at
        // the route level so the approve/edit separation cannot be quietly merged later.
        $partnerId = (string) $this->partner()->id;

        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->uri() === "api/partners/{$partnerId}/approve"
                || $r->uri() === 'api/partners/{partner}/approve');

        $this->assertNotNull($route, 'The partner approve route is no longer registered.');
        $this->assertContains('permission:partners.approve', $route->gatherMiddleware());
        $this->assertNotContains(
            'permission:partners.edit',
            $route->gatherMiddleware(),
            'Partner approval must not be reachable with partners.edit alone.'
        );
    }

    public function test_normal_user_cannot_escalate_privileges_through_role_endpoints()
    {
        // The original SEC-03 finding: with only auth:sanctum, any logged-in user could
        // rewrite a role's permission set and grant themselves anything.
        $roleId = (string) $this->role()->id;

        $this->actingAs($this->user('client'))
            ->putJson("/api/roles/{$roleId}/permissions", ['permissions' => ['bookings.delete']])
            ->assertStatus(403);

        $this->actingAs($this->user('staff'))
            ->putJson("/api/roles/{$roleId}/permissions", ['permissions' => ['bookings.delete']])
            ->assertStatus(403);

        $this->actingAs($this->user('client'))
            ->getJson('/api/roles')
            ->assertStatus(403);
    }

    public function test_role_permission_mutation_is_a_no_op_for_unpermissioned_users()
    {
        // Proves the denial is a real block and not merely a 403 on a different failure:
        // the stored permission set must be unchanged after the refused request.
        $role = $this->role();
        $before = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->pluck('permission')
            ->sort()
            ->values()
            ->all();

        $this->actingAs($this->user('client'))
            ->putJson("/api/roles/{$role->id}/permissions", [
                'permissions' => ['bookings.delete', 'payments.delete'],
            ])
            ->assertStatus(403);

        $after = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->pluck('permission')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $before,
            $after,
            'SEC-03 REGRESSION: an unpermissioned user changed a role\'s permission set.'
        );
    }
}
