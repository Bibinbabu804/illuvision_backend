<?php

namespace App\Infrastructure\Auth\Contracts;

/**
 * GoogleIdentityProviderInterface
 *
 * Defines the contract for Google OAuth identity verification.
 */
interface GoogleIdentityProviderInterface
{
    /**
     * Verify Google ID token and return user profile array or null on failure.
     *
     * @param string $idToken
     * @return array{sub: string, email: string, name?: string, picture?: string}|null
     */
    public function verifyIdToken(string $idToken): ?array;
}
