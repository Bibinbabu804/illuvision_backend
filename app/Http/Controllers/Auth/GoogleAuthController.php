<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Infrastructure\Auth\Contracts\GoogleIdentityProviderInterface;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleAuthController extends Controller
{
    /**
     * Handle Google login/registration.
     *
     * Expects a Google ID token in the request body.
     * Verifies the token with Google's tokeninfo endpoint,
     * then either logs in an existing user or creates a new one.
     */
    public function __invoke(Request $request, GoogleIdentityProviderInterface $googleAuth)
    {
        $request->validate([
            'credential' => 'required|string',
        ]);

        // Verify the Google ID token using Google Identity Provider Adapter
        $googleUser = $googleAuth->verifyIdToken($request->credential);

        if (!$googleUser || empty($googleUser['email'])) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid Google token.'],
            ]);
        }

        $email = $googleUser['email'];
        $googleId = $googleUser['sub'];
        $name = $googleUser['name'] ?? explode('@', $email)[0];
        $avatar = $googleUser['picture'] ?? null;

        // Check if a user with this google_id already exists
        $user = User::where('google_id', $googleId)->first();

        if (!$user) {
            // Check if a user with this email already exists
            $user = User::where('email', $email)->first();

            if ($user) {
                // Link Google account to existing user
                $user->update([
                    'google_id' => $googleId,
                    'avatar' => $avatar ?? $user->avatar,
                ]);
            } else {
                $role = $request->input('role', 'freelancer');
                if (!in_array($role, ['freelancer', 'client'])) {
                    $role = 'freelancer';
                }

                // Create new user with Google data
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'google_id' => $googleId,
                    'password' => Hash::make(Str::random(32)),
                    'avatar' => $avatar,
                    'role' => $role,
                    'email_verified_at' => now(),
                    'status' => 'active',
                ]);
            }
        }

        // Generate auth token
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ], 'Authenticated with Google successfully');
    }
}
