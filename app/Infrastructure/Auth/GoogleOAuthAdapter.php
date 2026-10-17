<?php

namespace App\Infrastructure\Auth;

use App\Infrastructure\Auth\Contracts\GoogleIdentityProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GoogleOAuthAdapter
 *
 * Implements GoogleIdentityProviderInterface by verifying Google OAuth tokens
 * with Google's tokeninfo and userinfo endpoints.
 */
class GoogleOAuthAdapter implements GoogleIdentityProviderInterface
{
    protected string $tokenInfoEndpoint = 'https://oauth2.googleapis.com/tokeninfo';
    protected string $userInfoEndpoint = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function verifyIdToken(string $token): ?array
    {
        // 1. Try verifying as an id_token via Google's tokeninfo endpoint
        try {
            $response = Http::get($this->tokenInfoEndpoint, [
                'id_token' => $token,
            ]);

            if ($response->successful() && $response->json('email')) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::info('Google ID token verification failed, trying userinfo: ' . $e->getMessage());
        }

        // 2. Try verifying as an OAuth2 access_token via Google's userinfo endpoint
        try {
            $response = Http::withToken($token)->get($this->userInfoEndpoint);

            if ($response->successful() && $response->json('email')) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::warning('Google userinfo verification failed: ' . $e->getMessage());
        }

        return null;
    }
}
