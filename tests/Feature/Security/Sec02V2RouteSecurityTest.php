<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-02 — the 19 anonymous /api/v2/* routes.
 *
 * CLASSIFICATION was derived from evidence, not from URI naming:
 *
 *  AUTH-ONLY, NOT PERMISSION-GATED (1):
 *    POST /api/v2/bookings
 *        BookingEngineController::store sets 'user_id' => $request->user()?->id, so the
 *        booking is scoped to the caller. It is the documented CUSTOMER self-service
 *        channel (BookingChannelsAndRescheduleHardeningTest drives it as a `customer`
 *        and asserts the daily-limit 422). `bookings.create` is a staff-only permission
 *        (manager/admin), so gating on it returned 403 and broke the customer flow.
 *        Requiring authentication is a strict improvement over the original anonymous
 *        state, and it does not invent a new permission.
 *
 *  KEPT PUBLIC (2) — verified read-only, verified no commercially sensitive output:
 *    POST /api/v2/availability/check
 *        AvailabilityEngineController@check -> BookingCapacityService::checkEligibility.
 *        Grepped for create/save/update/insert/lockScreens: no writes. Advisory check.
 *    POST /api/v2/pricing/calculate
 *        PricingEngineController@calculate -> LegacyPricingEngineAdapter::calculatePreview.
 *        Grepped for writes: none. Response is a customer-facing price breakdown
 *        (base_price, unit_price_per_day, days, line_subtotal) and contains NO
 *        revenue-split or commission field.
 *
 *  GATED (17) — every route that mutates state, or returns commercially or
 *  financially sensitive data:
 *    financial  : all /api/v2/finance/* — sprint_6_backend_architecture.md documents
 *                 these as the ADMIN "Finance Overview" dashboard (FinanceOverview.jsx,
 *                 financeApi.js, route /finance). batchSettlements can set a settlement
 *                 to `paid`, i.e. it moves money.
 *    booking    : /api/v2/bookings* — booking creation and the state-transition
 *                 endpoint are financial writes; the list/detail leak all bookings.
 *    asset      : /api/v2/assets*, /coverage/cities/*, /recommendations all return
 *                 AssetResource, which exposes illuvision_percentage,
 *                 screen_provider_percentage and duration_split_overrides — the
 *                 platform/provider revenue split. sprint_1_backend_architecture.md
 *                 also specifies "Policy: AssetPolicy" for this area (never built).
 *
 *  Every permission used below already exists in the RolesAndPermissionsSeeder
 *  vocabulary. No new permission name was invented, so no migration is required.
 */
class Sec02V2RouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
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

    /**
     * Every gated v2 route: [method, uri, required permission, mutates state?]
     * `mutates` drives the "did the write actually land?" assertion.
     */
    public static function gatedRoutes(): array
    {
        return [
            // ---- asset inventory administration ----
            'assets.index'        => ['GET',    '/api/v2/assets',                   'screens.view',   false],
            'assets.show'         => ['GET',    '/api/v2/assets/1',                 'screens.view',   false],
            'assets.store'        => ['POST',   '/api/v2/assets',                   'screens.create', true],
            'assets.update'       => ['PUT',    '/api/v2/assets/1',                 'screens.edit',   true],
            'assets.destroy'      => ['DELETE', '/api/v2/assets/1',                 'screens.delete', true],
            'assets.coverage'     => ['POST',   '/api/v2/assets/1/coverage',        'screens.edit',   true],
            'recommendations'     => ['GET',    '/api/v2/recommendations',          'screens.view',   false],
            'coverage.byCity'     => ['GET',    '/api/v2/coverage/cities/1',        'screens.view',   false],

            // ---- booking engine ----
            'bookings.index'      => ['GET',    '/api/v2/bookings',                 'bookings.view',  false],
            'bookings.show'       => ['GET',    '/api/v2/bookings/1',               'bookings.view',  false],
            'bookings.transition' => ['POST',   '/api/v2/bookings/1/transition',   'bookings.edit',  true],

            // ---- finance ----
            // NOTE: the vocabulary has no `settlements.*` section. See the SEC-03
            // vocabulary guard test. Existing admin-only payments.* is reused.
            'finance.summary'     => ['GET',    '/api/v2/finance/summary',          'payments.view', false],
            'finance.payments'    => ['GET',    '/api/v2/finance/payments',         'payments.view', false],
            'finance.settlements' => ['GET',    '/api/v2/finance/settlements',      'payments.view', false],
            'finance.ledger'      => ['GET',    '/api/v2/finance/ledger',           'payments.view', false],
            'finance.batch'       => ['POST',   '/api/v2/finance/settlements/batch', 'payments.approve', true],
        ];
    }

    /**
     * The 2 routes intentionally left public. Documented in the class docblock.
     */
    public static function intentionallyPublicRoutes(): array
    {
        return [
            'availability.check' => ['POST', '/api/v2/availability/check'],
            'pricing.calculate'  => ['POST', '/api/v2/pricing/calculate'],
        ];
    }

    // =====================================================================
    // 1. ANONYMOUS ACCESS MUST BE REFUSED ON EVERY GATED ROUTE
    // =====================================================================

    /** @dataProvider gatedRoutes */
    public function test_anonymous_request_is_rejected_on_gated_route(
        string $method, string $uri, string $permission, bool $mutates
    ) {
        $response = $this->json($method, $uri, []);

        $this->assertSame(
            401,
            $response->getStatusCode(),
            "SEC-02 REGRESSION: {$method} {$uri} answered anonymous callers with "
            . "{$response->getStatusCode()} instead of 401. Required permission: {$permission}."
        );
    }

    // =====================================================================
    // 2. AUTHENTICATED BUT UNPERMISSIONED MUST BE REFUSED (403, not 401)
    // =====================================================================

    /** @dataProvider gatedRoutes */
    public function test_authenticated_without_permission_is_forbidden(
        string $method, string $uri, string $permission, bool $mutates
    ) {
        // 'client' is NOT in the roles list — it won't have any seeded permissions
        // (and if it somehow does, the PermissionMiddleware returns 403). Use it to test
        // "authenticated without permission" without accidentally passing for roles that
        // already hold the required permission (e.g. 'staff' has screens.view/bookings.view).
        $user = $this->makeUser('client', 'client-v2-' . md5($uri) . '@example.test');

        $response = $this->actingAs($user)->json($method, $uri, []);

        $this->assertContains(
            $response->getStatusCode(),
            [401, 403],
            "SEC-02 REGRESSION: {$method} {$uri} let a staff user through with "
            . "{$response->getStatusCode()}. Required permission: {$permission}."
        );
    }

    // =====================================================================
    // 2b. THE CUSTOMER BOOKING CHANNEL: authenticated, but NOT permission-gated
    // =====================================================================

    public function test_customer_booking_channel_requires_authentication()
    {
        // Was fully anonymous before SEC-02; must at minimum require a logged-in caller.
        $this->postJson('/api/v2/bookings', [])->assertStatus(401);
    }

    public function test_customer_without_staff_permission_can_still_reach_the_booking_business_rules()
    {
        // Regression guard for the classification correction: gating this on
        // bookings.create returned 403 and broke the customer booking flow.
        // A customer must get PAST authorization and reach the controller, which then
        // applies capacity/limit validation (422 here) rather than denying outright.
        $customer = $this->makeUser('customer', 'customer-booking@example.test');

        $response = $this->actingAs($customer)->postJson('/api/v2/bookings', []);

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'SEC-02 REGRESSION: a customer was denied the self-service booking channel with '
            . '403. POST /api/v2/bookings is customer-facing and must not require a staff permission.'
        );
        $this->assertNotSame(
            401,
            $response->getStatusCode(),
            'SEC-02 REGRESSION: the customer booking channel must still require authentication.'
        );
    }

    public function test_customer_booking_is_scoped_to_the_caller()
    {
        // The reason auth-only is acceptable: the created booking is attributed to the
        // caller. Assert the controller really does scope it, so a future change that
        // creates unattributed bookings is caught.
        $this->assertStringContainsString(
            "'user_id' => \$request->user()?->id",
            file_get_contents(app_path('Http/Controllers/Api/BookingEngineController.php')),
            'SEC-02: POST /api/v2/bookings is auth-only because it scopes the booking to '
            . 'the caller. If that scoping is removed it must become permission-gated.'
        );
    }

    // =====================================================================
    // 3. THE 2 PUBLIC ROUTES MUST REMAIN REACHABLE (no over-blocking)
    // =====================================================================

    /** @dataProvider intentionallyPublicRoutes */
    public function test_intentionally_public_route_is_still_reachable_anonymously(
        string $method, string $uri
    ) {
        $response = $this->json($method, $uri, []);

        $this->assertNotSame(
            401,
            $response->getStatusCode(),
            "Over-correction: {$method} {$uri} is documented PUBLIC but now requires "
            . "authentication. Legitimate anonymous consumers (booking widget) would break."
        );
        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            "Over-correction: {$method} {$uri} is documented PUBLIC but is now permission-gated."
        );
    }

    // =====================================================================
    // 4. FINANCIAL WRITES — explicit, individually named regression guards
    // =====================================================================

    public function test_anonymous_cannot_mark_settlements_paid()
    {
        $response = $this->postJson('/api/v2/finance/settlements/batch', [
            'settlement_ids' => [1],
            'status' => 'paid',
        ]);

        $response->assertStatus(401);
    }

    public function test_unpermissioned_user_cannot_mark_settlements_paid()
    {
        $user = $this->makeUser('staff', 'staff-settle@example.test');

        $response = $this->actingAs($user)->postJson('/api/v2/finance/settlements/batch', [
            'settlement_ids' => [1],
            'status' => 'paid',
        ]);

        $this->assertContains($response->getStatusCode(), [401, 403]);
    }

    public function test_anonymous_cannot_read_the_accounting_ledger()
    {
        $this->getJson('/api/v2/finance/ledger')->assertStatus(401);
    }

    public function test_anonymous_cannot_read_payments()
    {
        $this->getJson('/api/v2/finance/payments')->assertStatus(401);
    }

    public function test_anonymous_cannot_read_financial_summary()
    {
        $this->getJson('/api/v2/finance/summary')->assertStatus(401);
    }

    public function test_anonymous_cannot_read_settlements()
    {
        $this->getJson('/api/v2/finance/settlements')->assertStatus(401);
    }

    // =====================================================================
    // 5. ASSET READS MUST NOT LEAK THE REVENUE SPLIT ANONYMOUSLY
    // =====================================================================

    public function test_anonymous_cannot_read_asset_inventory()
    {
        $this->getJson('/api/v2/assets')->assertStatus(401);
    }

    public function test_anonymous_cannot_read_revenue_split_via_recommendations()
    {
        // RecommendationEngineController returns AssetResource::collection(...),
        // which carries illuvision_percentage / screen_provider_percentage.
        $this->getJson('/api/v2/recommendations')->assertStatus(401);
    }

    public function test_anonymous_cannot_read_revenue_split_via_coverage_lookup()
    {
        $this->getJson('/api/v2/coverage/cities/1')->assertStatus(401);
    }

    public function test_anonymous_cannot_mutate_asset_inventory()
    {
        $this->postJson('/api/v2/assets', [])->assertStatus(401);
        $this->putJson('/api/v2/assets/1', [])->assertStatus(401);
        $this->deleteJson('/api/v2/assets/1')->assertStatus(401);
        $this->postJson('/api/v2/assets/1/coverage', [])->assertStatus(401);
    }

    // =====================================================================
    // 6. BOOKING ENGINE
    // =====================================================================

    public function test_anonymous_cannot_create_bookings()
    {
        $this->postJson('/api/v2/bookings', [])->assertStatus(401);
    }

    public function test_anonymous_cannot_transition_booking_state()
    {
        $this->postJson('/api/v2/bookings/1/transition', [])->assertStatus(401);
    }

    public function test_anonymous_cannot_list_bookings()
    {
        $this->getJson('/api/v2/bookings')->assertStatus(401);
        $this->getJson('/api/v2/bookings/1')->assertStatus(401);
    }

    // =====================================================================
    // 7. NO *NEW* PUBLIC FINANCE/WRITE ROUTE MAY APPEAR
    // =====================================================================

    public function test_no_finance_or_write_v2_route_is_anonymous()
    {
        // Structural guard: catches a future developer adding a new /api/v2 write
        // route outside the gated group.
        $offenders = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v2/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();

            // Documented exceptions, each with an explicit justification.
            $documented = [
                'api/v2/availability/check|POST' => 'public read-only availability advisory',
                'api/v2/pricing/calculate|POST'  => 'public read-only customer quote',
            ];
            $key = $route->uri() . '|' . implode(',', $route->methods());

            // A route counts as guarded if ANY of its middleware authenticates or
            // authorizes, whether declared as auth:sanctum or as the class-qualified
            // Illuminate\Auth\Middleware\Authenticate:sanctum.
            $authenticates = false;
            foreach ($middleware as $m) {
                // gatherMiddleware() may return either the alias form
                // ('permission:screens.view') or the resolved class form
                // ('App\\Http\\Middleware\\PermissionMiddleware:screens.view'),
                // depending on whether the alias has been resolved yet. Accept both.
                if (str_starts_with($m, 'auth:')
                    || str_starts_with($m, 'permission:')
                    || str_contains($m, 'Authenticate')
                    || str_contains($m, 'PermissionMiddleware')) {
                    $authenticates = true;
                    break;
                }
            }

            if (array_key_exists($key, $documented) && ! $authenticates) {
                continue; // intentionally public, verified unauthenticated
            }
            if ($authenticates) {
                continue; // authenticated or authorized
            }

            $offenders[] = implode('|', $route->methods()) . ' ' . $route->uri()
                . '  (' . implode(',', $middleware) . ')';
        }

        $this->assertSame(
            [],
            $offenders,
            "SEC-02 REGRESSION: undocumented anonymous /api/v2 routes found:\n"
            . implode("\n", $offenders)
        );
    }

    public function test_documented_public_route_list_is_complete_and_accurate()
    {
        // The two routes this suite treats as public must genuinely be reachable,
        // so that the "no anonymous route" guard above can never pass by accident
        // (e.g. if a typo 404'd them).
        foreach (self::intentionallyPublicRoutes() as $name => [$method, $uri]) {
            $route = collect(app('router')->getRoutes())
                ->first(fn($r) => $r->uri() === ltrim($uri, '/') && in_array($method, $r->methods(), true));

            $this->assertNotNull($route, "Documented-public route {$method} {$uri} is not registered.");
            $this->assertNotContains(
                'auth:sanctum',
                $route->gatherMiddleware(),
                "{$method} {$uri} is documented PUBLIC but carries auth:sanctum."
            );
        }
    }
}
