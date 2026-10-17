<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Authorization\RolePermissionChecker;
use Closure;
use Illuminate\Http\Request;

class PermissionMiddleware
{
    public function __construct(
        protected RolePermissionChecker $permissions
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Super Admin bypass check
        if ($user->role === 'super-admin' || $user->role === 'superadmin') {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if (! $this->userHasPermission($user, $permission)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Forbidden. You do not have the required permission: '.$permission,
                ], 403);
            }
        }

        return $next($request);
    }

    public function userHasPermission(User $user, string $permission): bool
    {
        return $this->permissions->userHasPermission($user, $permission);
    }

    public static function clearPermissionCache(?string $roleSlug = null): void
    {
        app(RolePermissionChecker::class)->clearCache($roleSlug);
    }
}
