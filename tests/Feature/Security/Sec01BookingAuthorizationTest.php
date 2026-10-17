<?php

namespace Tests\Feature\Security;

use App\Domain\Booking\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Support\Authorization\RolePermissionChecker;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-01 — PATCH /api/bookings/{booking} bypasses permission:bookings.edit
 *
 * ROOT CAUSE (verified against the live route table, not inferred):
 *
 *   routes/api.php:216  Route::apiResource('bookings', ...)->only([... 'update' ...])
 *       registers  PUT|PATCH  with middleware  ['api','auth:sanctum']
 *
 *   routes/api.php:567  Route::put('/bookings/{booking}', ...)->middleware('permission:bookings.edit')
 *       registers  PUT  with middleware  ['api','auth:sanctum','permission:bookings.view','permission:bookings.edit']
 *
 * Laravel resolves a verb+URI through a per-method lookup table, and the LAST
 * registration wins. The explicit PUT therefore overrides only the PUT half of
 * the apiResource's `PUT|PATCH` entry. PATCH keeps resolving to the apiResource
 * route, which is authenticated but NOT permission-checked.
 *
 * BookingController::update() then validates
 *   'price'  => 'sometimes|numeric|min:0'
 *   'status' => 'sometimes|string'          (no Rule::in constraint)
 * and RescheduleBookingUseCase persists the payload, so an unprivileged
 * authenticated user could set both the booking status and its price.
 *
 * These tests are the regression gate. test_vulnerability_* cases assert the
 * DENIED outcome; they FAIL against the unpatched routes and PASS after the fix.
 */
class Sec01BookingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'booking_reference' => 'ILL-SEC01-' . rand(100000, 999999),
            'title' => 'SEC-01 subject booking',
            'campaign_title' => 'SEC-01 subject booking',
            'advertiser_name' => 'Advertiser',
            'advertiser_email' => 'advertiser@example.test',
            'advertiser_phone' => '9000000000',
            'start_time' => '2026-10-01 00:00:00',
            'end_time' => '2026-10-05 23:59:59',
            'status' => BookingStatus::PENDING_APPROVAL->value,
            'price' => 5000.00,
        ], $overrides));
    }

    private function makeUser(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst(str_replace('-', ' ', $role)) . ' User',
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function statusOf(Booking $b): string
    {
        return (string) ($b->fresh()->status instanceof BookingStatus
            ? $b->fresh()->status->value
            : $b->fresh()->status);
    }

    // =====================================================================
    // 1. UNAUTHORIZED — authenticated, WITHOUT bookings.edit  => DENIED
    // =====================================================================

    public function test_vulnerability_user_without_bookings_edit_is_denied_patch()
    {
        $user = $this->makeUser('staff', 'staff-no-edit@example.test');

        // Precondition, asserted explicitly so the test cannot pass for the
        // wrong reason (e.g. if the seeder ever granted staff bookings.edit).
        $this->assertFalse(
            app(RolePermissionChecker::class)->userHasPermission($user, 'bookings.edit'),
            'Precondition failed: staff must NOT hold bookings.edit for this test to be meaningful.'
        );

        $booking = $this->makeBooking();

        $response = $this->actingAs($user)
            ->patchJson("/api/bookings/{$booking->id}", [
                'status' => BookingStatus::APPROVED->value,
                'price' => 1.00,
            ]);

        $response->assertForbidden();

        // The financial write must not have landed.
        $this->assertNotSame(BookingStatus::APPROVED->value, $this->statusOf($booking),
            'SEC-01 REGRESSION: an unprivileged user changed booking status.');
        $this->assertEquals(5000.00, (float) $booking->fresh()->price,
            'SEC-01 REGRESSION: an unprivileged user changed booking price.');
    }

    public function test_vulnerability_staff_cannot_escalate_booking_state_via_patch()
    {
        // 'staff' holds bookings.view but not bookings.edit — the exact shape of
        // the Phase 0.16 exploit, where the attacker was a plain role-'user'.
        $user = $this->makeUser('staff', 'staff-escalation@example.test');
        $booking = $this->makeBooking(['status' => BookingStatus::PENDING_APPROVAL->value]);

        $this->actingAs($user)
            ->patchJson("/api/bookings/{$booking->id}", ['status' => BookingStatus::APPROVED->value])
            ->assertForbidden();

        $this->assertSame(BookingStatus::PENDING_APPROVAL->value, $this->statusOf($booking));
    }

    // =====================================================================
    // 2. UNAUTHENTICATED  => 401
    // =====================================================================

    public function test_unauthenticated_patch_is_rejected_with_401()
    {
        $booking = $this->makeBooking();

        $response = $this->patchJson("/api/bookings/{$booking->id}", [
            'status' => BookingStatus::APPROVED->value,
            'price' => 1.00,
        ]);

        $response->assertStatus(401);
        $this->assertSame(BookingStatus::PENDING_APPROVAL->value, $this->statusOf($booking));
    }

    // =====================================================================
    // 3. AUTHORIZED — WITH bookings.edit  => ALLOWED
    // =====================================================================

    public function test_authorized_user_with_bookings_edit_can_patch()
    {
        // manager holds bookings.* per the RolesAndPermissionsSeeder grants.
        $user = $this->makeUser('manager', 'manager-edit@example.test');

        $this->assertTrue(
            app(RolePermissionChecker::class)->userHasPermission($user, 'bookings.edit'),
            'Precondition failed: manager must hold bookings.edit for this test to be meaningful.'
        );

        $booking = $this->makeBooking();

        $response = $this->actingAs($user)
            ->patchJson("/api/bookings/{$booking->id}", [
                'title' => 'Renamed by authorized manager',
                'notes' => 'updated via authorized PATCH',
            ]);

        $response->assertOk();
        $this->assertEquals(
            'Renamed by authorized manager',
            $booking->fresh()->title,
            'An authorized bookings.edit holder must still be able to PATCH.'
        );
    }

    public function test_authorized_user_can_still_use_put_unchanged()
    {
        // Guards against 'fixing' SEC-01 by removing the verb rather than gating it.
        $user = $this->makeUser('manager', 'manager-put@example.test');
        $booking = $this->makeBooking();

        $this->actingAs($user)
            ->putJson("/api/bookings/{$booking->id}", ['title' => 'Renamed via PUT'])
            ->assertOk();

        $this->assertEquals('Renamed via PUT', $booking->fresh()->title);
    }

    public function test_super_admin_retains_bypass_on_patch()
    {
        // The documented super-admin bypass in PermissionMiddleware must survive.
        $user = $this->makeUser('super-admin', 'root-patch@example.test');
        $booking = $this->makeBooking();

        $this->actingAs($user)
            ->patchJson("/api/bookings/{$booking->id}", ['title' => 'Renamed by super-admin'])
            ->assertOk();

        $this->assertEquals('Renamed by super-admin', $booking->fresh()->title);
    }

    // =====================================================================
    // 4. PARITY — PATCH must be gated exactly like PUT
    // =====================================================================

    public function test_patch_and_put_are_gated_identically()
    {
        $user = $this->makeUser('staff', 'staff-parity@example.test');
        $booking = $this->makeBooking();

        $put = $this->actingAs($user)->putJson("/api/bookings/{$booking->id}", ['title' => 'x']);
        $patch = $this->actingAs($user)->patchJson("/api/bookings/{$booking->id}", ['title' => 'x']);

        $this->assertSame(
            $put->getStatusCode(),
            $patch->getStatusCode(),
            "PATCH and PUT must have identical authorization outcomes. PUT={$put->getStatusCode()} PATCH={$patch->getStatusCode()}"
        );
    }

    public function test_every_registration_for_the_update_route_is_permission_gated()
    {
        // SEC-01 asserted one specific defect: an ungated `PUT|PATCH` apiResource entry
        // shadowed only the PUT half. Asserting the *effective* dispatch route alone is
        // not enough, because a shadowed unpermissioned entry can still be registered
        // and become live again after an unrelated reordering.
        //
        // So: EVERY route object registered for api/bookings/{booking} that answers PUT
        // or PATCH must carry permission:bookings.edit. This holds regardless of which
        // entry Laravel dispatches, and fails on the original code.
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() !== 'api/bookings/{booking}') {
                continue;
            }
            if (! array_intersect(['PUT', 'PATCH'], $route->methods())) {
                continue;
            }

            $checked++;
            $middleware = $route->gatherMiddleware();

            $this->assertContains(
                'permission:bookings.edit',
                $middleware,
                'SEC-01 REGRESSION: a PUT/PATCH registration for api/bookings/{booking} '
                . 'is missing permission:bookings.edit. Middleware: ' . json_encode($middleware)
            );
        }

        $this->assertGreaterThanOrEqual(
            1,
            $checked,
            'No PUT or PATCH route is registered for api/bookings/{booking} at all.'
        );
    }

    public function test_effective_dispatch_route_for_put_and_patch_is_permission_gated()
    {
        // Second, independent guard: resolve the route the kernel actually dispatches to,
        // for BOTH verbs. `RouteCollection::match()` is the only way to observe this,
        // since scanning the collection reports shadowed entries too.
        foreach (['PUT', 'PATCH'] as $verb) {
            $request = \Illuminate\Http\Request::create('/api/bookings/1', $verb);
            $effective = app('router')->getRoutes()->match($request);

            $this->assertSame(
                'api/bookings/{booking}',
                $effective->uri(),
                "The effective {$verb} route resolved to a different URI."
            );

            $middleware = $effective->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, "{$verb} must remain authenticated.");
            $this->assertContains(
                'permission:bookings.edit',
                $middleware,
                "SEC-01 REGRESSION: the effective {$verb} route is missing permission:bookings.edit. "
                . 'Middleware: ' . json_encode($middleware)
            );
        }
    }

    // =====================================================================
    // 5. SCOPE — the fix must not grant more than bookings.edit
    // =====================================================================

    public function test_bookings_view_alone_does_not_grant_patch()
    {
        // A role holding bookings.view but not bookings.edit must stay denied.
        $checker = app(RolePermissionChecker::class);
        $grants = $checker->permissionsForRole('staff');

        $this->assertContains('bookings.view', $grants, 'Precondition: staff should hold bookings.view.');
        $this->assertNotContains('bookings.edit', $grants, 'Precondition: staff must not hold bookings.edit.');

        $user = $this->makeUser('staff', 'staff-viewonly@example.test');
        $booking = $this->makeBooking();

        $this->actingAs($user)
            ->patchJson("/api/bookings/{$booking->id}", ['title' => 'should not apply'])
            ->assertForbidden();

        $this->assertEquals('SEC-01 subject booking', $booking->fresh()->title);
    }
}
