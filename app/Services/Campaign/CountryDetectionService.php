<?php

namespace App\Services\Campaign;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CountryDetectionService
 *
 * Resolves visitor country from IP address using ip-api.com (free tier).
 * Falls back to a default (IN) if the API fails.
 */
class CountryDetectionService
{
    /**
     * ip-api.com endpoint (free, no key).
     */
    protected string $apiUrl = 'http://ip-api.com/json/';

    /**
     * Default country code if detection fails.
     */
    protected string $defaultCountry = 'IN';

    /**
     * Detect country code from an IP address.
     *
     * @param string|null $ip  Client IP. Null = use request IP (for server-side).
     * @return string  ISO 3166-1 alpha-2 country code (e.g. 'IN', 'GB', 'US').
     */
    public function detect(?string $ip = null): string
    {
        // If no IP provided, we can't detect — return default
        if (!$ip || $ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
            return $this->defaultCountry;
        }

        try {
            $response = Http::timeout(5)->get($this->apiUrl . $ip);

            if (!$response->successful()) {
                Log::warning('ip-api request failed', [
                    'ip' => $ip,
                    'status' => $response->status(),
                ]);
                return $this->defaultCountry;
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'success') {
                Log::warning('ip-api returned non-success', ['ip' => $ip, 'data' => $data]);
                return $this->defaultCountry;
            }

            return $data['countryCode'] ?? $this->defaultCountry;

        } catch (\Exception $e) {
            Log::warning('Country detection failed: ' . $e->getMessage(), ['ip' => $ip]);
            return $this->defaultCountry;
        }
    }
}
