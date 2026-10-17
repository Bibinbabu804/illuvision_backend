<?php

namespace Tests\Unit\Infrastructure\ExchangeRates;

use App\Infrastructure\ExchangeRates\ExchangeRateApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateApiClientTest extends TestCase
{
    public function test_fetch_rates_success_returns_rates_array(): void
    {
        Http::fake([
            'https://api.exchangerate-api.com/v4/latest/INR' => Http::response([
                'base' => 'INR',
                'rates' => [
                    'USD' => 0.012,
                    'EUR' => 0.011,
                    'GBP' => 0.0095,
                ],
            ], 200),
        ]);

        $client = new ExchangeRateApiClient();
        $rates = $client->fetchRates('https://api.exchangerate-api.com/v4/latest/INR');

        $this->assertIsArray($rates);
        $this->assertEquals(0.012, $rates['USD']);
        $this->assertEquals(0.011, $rates['EUR']);
        $this->assertEquals(0.0095, $rates['GBP']);
    }

    public function test_fetch_rates_failure_returns_empty_array(): void
    {
        Http::fake([
            'https://api.exchangerate-api.com/v4/latest/INR' => Http::response([], 500),
        ]);

        $client = new ExchangeRateApiClient();
        $rates = $client->fetchRates('https://api.exchangerate-api.com/v4/latest/INR');

        $this->assertIsArray($rates);
        $this->assertEmpty($rates);
    }
}
