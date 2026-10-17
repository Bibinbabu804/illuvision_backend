<?php

namespace App\Support\Authorization;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * RolePermissionChecker
 *
 * The single owner of the "does this user hold this permission?" decision.
 *
 * WHY THIS CLASS EXISTS (Phase 0.13):
 * `User::hasPermission()` used to answer that question by service-locating
 * `App\Http\Middleware\PermissionMiddleware` — an Eloquent model reaching
 * directly into the HTTP presentation layer. That is a Model -> Http
 * dependency, which the architecture rules disallow. It also meant the
 * permission algorithm had no home of its own: the middleware happened to
 * expose a public method that the model reused.
 *
 * This class extracts that algorithm verbatim so both callers can depend on an
 * authorization boundary instead of on the transport layer. The middleware is
 * now a thin HTTP adapter over this checker.
 *
 * BEHAVIOURAL CONTRACT — UNCHANGED:
 *  - Resolution is by role slug only, via `Role::grantedPermissions()`.
 *  - Results are cached under `role_permissions:{slug}` for 3600 seconds.
 *  - The comparison is a strict `in_array` against the granted list.
 *  - A missing role resolves to an empty permission list, not an error.
 *  - There is deliberately NO super-admin bypass here. The super-admin bypass
 *    lives in `PermissionMiddleware::handle()` and has never applied to
 *    `User::hasPermission()`. That asymmetry is a documented, test-pinned
 *    behaviour of the existing external contract and must not be "fixed" as a
 *    side effect of this extraction. See docs/audit/phase-0.11-0.12-final-report.md
 *    item 7 in section 7.
 */
class RolePermissionChecker
{
    public const CACHE_TTL_SECONDS = 3600;

    public function userHasPermission(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsForRole($user->role), true);
    }

    /**
     * @return array<int, string>
     */
    public function permissionsForRole(?string $roleSlug): array
    {
        if ($roleSlug === null || $roleSlug === '') {
            return [];
        }

        return Cache::remember(
            $this->cacheKey($roleSlug),
            self::CACHE_TTL_SECONDS,
            function () use ($roleSlug) {
                $role = Role::where('slug', $roleSlug)->first();

                if (! $role) {
                    return [];
                }

                return $role->grantedPermissions();
            }
        );
    }

    public function cacheKey(?string $roleSlug): string
    {
        return "role_permissions:{$roleSlug}";
    }

    public function clearCache(?string $roleSlug = null): void
    {
        if ($roleSlug) {
            Cache::forget($this->cacheKey($roleSlug));

            return;
        }

        foreach (Role::pluck('slug') as $slug) {
            Cache::forget($this->cacheKey($slug));
        }
    }
}
