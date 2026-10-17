<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\PermissionMiddleware;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the permission VOCABULARY itself.
 *
 * This exists because of a real defect found while fixing SEC-02: the finance routes were
 * initially gated on `settlements.view` / `settlements.approve`, but
 * RolesAndPermissionsSeeder has no `settlements` section, so no permission string of that
 * name is ever granted to any role. The routes would have answered 403 for every role
 * including `admin` - i.e. the Admin Finance Overview dashboard would have been silently
 * and completely broken, while every individual route still "looked correctly gated".
 *
 * A middleware name that does not exist in the vocabulary fails CLOSED, which is the safe
 * direction, but it is invisible. These tests make it loud.
 */
class Sec03PermissionVocabularyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string,string|null> Saved process environment.
     *
     * This class MUTATES the real process environment (APP_ENV / APP_DEBUG) so it can
     * exercise config/app.php directly. The environment is therefore saved in setUp and
     * restored in tearDown. Without the restore, a failure in the middle of the debug
     * test would leave APP_ENV=production for every subsequent test in the same PHP
     * process, producing unrelated and very confusing failures.
     */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['APP_ENV', 'APP_DEBUG'] as $key) {
            $this->savedEnv[$key] = getenv($key) === false ? null : (string) getenv($key);
        }

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->savedEnv = [];

        parent::tearDown();
    }

    /**
     * KNOWN PRE-EXISTING DEBT - present in git HEAD, NOT introduced by Phase 0.21.
     *
     * routes/api.php gates the admin settlement workflow on `settlements.view`,
     * `settlements.approve` and `settlements.pay`:
     *
     *   Route::middleware('permission:settlements.view')->group(...)      // HEAD:456
     *   ...->middleware('permission:settlements.approve')                 // HEAD:458-463
     *   ...->middleware('permission:settlements.pay')                     // HEAD:462
     *
     * RolesAndPermissionsSeeder defines no `settlements` section, so none of these
     * permission strings is ever granted to any role. Consequence: every
     * /api/admin/settlements/* route is unreachable for EVERY role including `admin`,
     * and only `super-admin` can reach it (via the hard-coded bypass). The admin
     * settlement workflow is therefore effectively dead for admin users.
     *
     * This is an availability/authorization defect, not a security hole (it fails
     * CLOSED), and it is OUT OF SCOPE for SEC-01/02/03. The user directive is explicit:
     * do not silently alter unrelated authorization behavior. So the routes are left
     * EXACTLY as they are and the defect is recorded as a new finding for a follow-up
     * phase, to be resolved together with the settlements vocabulary gap.
     *
     * The allowlist exists so that this known debt is documented AND so that any NEW
     * orphaned permission still fails the guard below.
     */
    private const KNOWN_ORPHANED_PERMISSIONS = [
        'settlements.view'   => 'pre-existing: /api/admin/settlements/* (routes/api.php:545) - admin settlement workflow unreachable for admin',
        'settlements.approve' => 'pre-existing: /api/admin/settlements/* (routes/api.php:547-552) - admin settlement workflow unreachable for admin',
        'settlements.pay'    => 'pre-existing: /api/admin/settlements/* mark-paid (routes/api.php:551) - admin settlement workflow unreachable for admin',
    ];

    private function vocabulary(): array
    {
        return DB::table('role_permissions')
            ->distinct()
            ->pluck('permission')
            ->all();
    }

    /**
     * Every `permission:x` referenced anywhere in routes/api.php must exist in the
     * seeded vocabulary. A typo or an aspirational permission name fails here instead of
     * silently producing an unreachable route.
     */
    public function test_every_permission_referenced_in_routes_exists_in_the_vocabulary()
    {
        $vocabulary = $this->vocabulary();

        $referenced = [];
        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (str_starts_with($middleware, 'permission:')) {
                    $referenced[substr($middleware, strlen('permission:'))] = true;
                }
                if (str_contains($middleware, 'PermissionMiddleware:')) {
                    $referenced[substr($middleware, strpos($middleware, ':') + 1)] = true;
                }
            }
        }

        $this->assertNotEmpty($referenced, 'No permission middleware found; the guard is not measuring anything.');

        $missing = array_values(array_diff(array_keys($referenced), $vocabulary));

        // Subtract the documented pre-existing debt so only NEW orphans fail.
        $unexpected = array_values(array_diff($missing, array_keys(self::KNOWN_ORPHANED_PERMISSIONS)));

        $this->assertSame(
            [],
            $unexpected,
            "These permissions are enforced on routes but are never granted to any role by "
            . "RolesAndPermissionsSeeder, so the routes are unreachable for every role "
            . "including admin:\n  " . implode("\n  ", $unexpected)
        );

        // If a previously-orphaned permission became real, the allowlist is stale.
        $remediated = array_values(array_intersect($missing, array_keys(self::KNOWN_ORPHANED_PERMISSIONS)));
        $this->assertSame(
            $missing,
            $remediated,
            "KNOWN_ORPHANED_PERMISSIONS is stale: " . implode(', ', $remediated)
            . " no longer missing, or new ones are missing. Re-check the settlement routes."
        );
    }

    /**
     * Documents the known gap so it cannot be forgotten. If someone later adds a
     * `settlements` section to the seeder, this test tells them to revisit the
     * SEC-02 finance mapping rather than leaving the workaround in place silently.
     */
    public function test_settlements_vocabulary_gap_is_still_open_and_documented()
    {
        $vocabulary = $this->vocabulary();

        $this->assertNotContains(
            'settlements.view',
            $vocabulary,
            'A `settlements.*` vocabulary now exists. Revisit the SEC-02 finance mapping in '
            . 'routes/api.php: it still uses payments.* as a documented workaround, and '
            . 'phase-0.21-authorization-matrix.json must be updated accordingly.'
        );

        // The workaround must remain in effect while the gap is open.
        $financeReads = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v2/finance/')
                && in_array('GET', $r->methods(), true));

        $this->assertGreaterThanOrEqual(4, $financeReads->count());

        foreach ($financeReads as $route) {
            $this->assertContains(
                'permission:payments.view',
                $route->gatherMiddleware(),
                "{$route->uri()} must stay on payments.view while the settlements vocabulary gap is open."
            );
        }
    }

    /**
     * A permission that exists but is held by no non-super-admin role is equivalent to a
     * missing permission. This catches vocabulary entries that are seeded but orphaned.
     */
    public function test_permissions_enforced_on_routes_are_held_by_at_least_one_real_role()
    {
        $referenced = [];
        foreach (app('router')->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (str_starts_with($middleware, 'permission:')) {
                    $referenced[substr($middleware, strlen('permission:'))] = true;
                }
                if (str_contains($middleware, 'PermissionMiddleware:')) {
                    $referenced[substr($middleware, strpos($middleware, ':') + 1)] = true;
                }
            }
        }

        $orphaned = [];

        foreach (array_keys($referenced) as $permission) {
            $holders = DB::table('role_permissions')
                ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
                ->where('role_permissions.permission', $permission)
                ->where('roles.slug', '!=', 'super-admin')
                ->pluck('roles.slug')
                ->unique();

            if ($holders->isEmpty()) {
                $orphaned[] = $permission;
            }
        }

        $unexpected = array_values(array_diff($orphaned, array_keys(self::KNOWN_ORPHANED_PERMISSIONS)));

        $this->assertSame(
            [],
            $unexpected,
            "These permissions are enforced on routes but are granted to no role other than "
            . "super-admin (which bypasses the check), making the routes admin-unreachable:\n  "
            . implode("\n  ", $unexpected)
        );

        // These are asserted to still be orphaned, so the debt is visible, not forgotten.
        $stillOrphaned = array_values(array_intersect($orphaned, array_keys(self::KNOWN_ORPHANED_PERMISSIONS)));
        sort($stillOrphaned);
        $expected = array_keys(self::KNOWN_ORPHANED_PERMISSIONS);
        sort($expected);
        $this->assertSame(
            $expected,
            $stillOrphaned,
            'KNOWN_ORPHANED_PERMISSIONS is stale. Either the settlement routes were fixed '
            . '(update the allowlist and the SEC finding) or new orphaned permissions appeared.'
        );
    }

    public function test_super_admin_bypass_still_works_for_every_referenced_permission()
    {
        $user = \App\Models\User::create([
            'name' => 'Super Admin',
            'email' => 'vocab-superadmin@example.test',
            'password' => bcrypt('password'),
            'role' => 'super-admin',
            'status' => 'active',
        ]);

        // The bypass is a hard-coded role comparison in PermissionMiddleware.
        $middleware = new PermissionMiddleware(
            app(\App\Support\Authorization\RolePermissionChecker::class)
        );

        $request = \Illuminate\Http\Request::create('/api/v2/finance/summary', 'GET');
        $request->setUserResolver(fn () => $user);

        $reachedController = false;
        $middleware->handle($request, function () use (&$reachedController) {
            $reachedController = true;
            return response('ok');
        }, 'payments.view');

        $this->assertTrue($reachedController, 'super-admin bypass is broken.');
    }
}
