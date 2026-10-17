<?php

namespace App\Infrastructure\ExchangeRates;

use App\Infrastructure\ExchangeRates\Contracts\ExchangeRateApiClientInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ExchangeRateApiClient
 *
 * Implements ExchangeRateApiClientInterface via HTTP client.
 */
class ExchangeRateApiClient implements ExchangeRateApiClientInterface
{
    public function fetchRates(string $url): array
    {
        try {
            $response = Http::timeout(15)->get($url);

            if (!$response->successful()) {
                Log::warning('ExchangeRate-API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $data = $response->json();
            return $data['rates'] ?? [];
        } catch (\Exception $e) {
            Log::error('ExchangeRate-API fetch failed: ' . $e->getMessage());
            return [];
        }
    }
}
