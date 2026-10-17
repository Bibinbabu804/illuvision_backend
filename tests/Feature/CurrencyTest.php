<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\Campaign\ExchangeRateService;
use App\Services\Campaign\CurrencyService;
use App\Services\Campaign\CountryDetectionService;
use App\Models\CurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected ExchangeRateService $exchangeRateService;
    protected CurrencyService $currencyService;
    protected CountryDetectionService $countryDetectionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->countryDetectionService = new CountryDetectionService();
        $this->exchangeRateService = new ExchangeRateService();
        $this->currencyService = new CurrencyService(
            $this->exchangeRateService,
            $this->countryDetectionService
        );
    }

    /** @test */
    public function it_returns_inr_as_default_rate_for_inr()
    {
        $rate = $this->exchangeRateService->getRate('INR');
        $this->assertEquals(1.0, $rate);
    }

    /** @test */
    public function it_returns_null_for_unsupported_currency()
    {
        $rate = $this->exchangeRateService->getRate('XYZ');
        $this->assertNull($rate);
    }

    /** @test */
    public function it_maps_country_to_currency_correctly()
    {
        $this->assertEquals('INR', $this->exchangeRateService->currencyForCountry('IN'));
        $this->assertEquals('GBP', $this->exchangeRateService->currencyForCountry('GB'));
        $this->assertEquals('USD', $this->exchangeRateService->currencyForCountry('US'));
        $this->assertEquals('EUR', $this->exchangeRateService->currencyForCountry('DE'));
        $this->assertEquals('AED', $this->exchangeRateService->currencyForCountry('AE'));
        $this->assertEquals('AUD', $this->exchangeRateService->currencyForCountry('AU'));
    }

    /** @test */
    public function it_falls_back_to_inr_for_unknown_country()
    {
        $this->assertEquals('INR', $this->exchangeRateService->currencyForCountry('XX'));
    }

    /** @test */
    public function it_converts_inr_to_gbp_correctly()
    {
        CurrencyRate::create([
            'currency_code' => 'GBP',
            'exchange_rate' => 0.009200,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);
        Cache::forget('currency_rate_GBP');

        $converted = $this->exchangeRateService->convertToDisplay(100000, 'GBP');
        $this->assertEquals(920.00, $converted);
    }

    /** @test */
    public function it_converts_inr_to_usd_correctly()
    {
        CurrencyRate::create([
            'currency_code' => 'USD',
            'exchange_rate' => 0.011600,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);
        Cache::forget('currency_rate_USD');

        $converted = $this->exchangeRateService->convertToDisplay(100000, 'USD');
        $this->assertEquals(1160.00, $converted);
    }

    /** @test */
    public function it_converts_inr_to_eur_correctly()
    {
        CurrencyRate::create([
            'currency_code' => 'EUR',
            'exchange_rate' => 0.011000,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);
        Cache::forget('currency_rate_EUR');

        $converted = $this->exchangeRateService->convertToDisplay(100000, 'EUR');
        $this->assertEquals(1100.00, $converted);
    }

    /** @test */
    public function it_converts_inr_to_aed_correctly()
    {
        CurrencyRate::create([
            'currency_code' => 'AED',
            'exchange_rate' => 0.042600,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);
        Cache::forget('currency_rate_AED');

        $converted = $this->exchangeRateService->convertToDisplay(100000, 'AED');
        $this->assertEquals(4260.00, $converted);
    }

    /** @test */
    public function it_handles_rounding_and_precision_correctly()
    {
        CurrencyRate::create([
            'currency_code' => 'GBP',
            'exchange_rate' => 0.009214,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);
        Cache::forget('currency_rate_GBP');

        // 15015 * 0.009214 = 138.34821 -> rounds to 138.35
        $converted = $this->exchangeRateService->convertToDisplay(15015, 'GBP');
        $this->assertEquals(138.35, $converted);
    }

    /** @test */
    public function it_handles_zero_small_and_large_amounts()
    {
        CurrencyRate::create([
            'currency_code' => 'GBP',
            'exchange_rate' => 0.009200,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);
        Cache::forget('currency_rate_GBP');

        // Zero
        $this->assertEquals(0.0, $this->exchangeRateService->convertToDisplay(0, 'GBP'));

        // Small amount (1 INR)
        $this->assertEquals(0.01, $this->exchangeRateService->convertToDisplay(1, 'GBP'));

        // Fractional amount (50.50 INR)
        $this->assertEquals(0.46, $this->exchangeRateService->convertToDisplay(50.50, 'GBP'));

        // Large amount (10,000,000 INR)
        $this->assertEquals(92000.0, $this->exchangeRateService->convertToDisplay(10000000, 'GBP'));
    }

    /** @test */
    public function it_preserves_inr_to_inr_amounts()
    {
        $this->assertEquals(100000.0, $this->exchangeRateService->convertToDisplay(100000, 'INR'));
        $this->assertEquals(98500.0, $this->exchangeRateService->convertToDisplay(98500, 'INR'));
    }

    /** @test */
    public function it_returns_inr_when_no_rate_available()
    {
        // For unsupported currency with no rate available, fallback to INR amount
        $converted = $this->exchangeRateService->convertToDisplay(1000, 'XYZ');
        $this->assertEquals(1000.0, $converted);
    }

    /** @test */
    public function it_returns_supported_currencies()
    {
        $currencies = $this->exchangeRateService->supportedCurrencies;
        $this->assertContains('INR', $currencies);
        $this->assertContains('GBP', $currencies);
        $this->assertContains('USD', $currencies);
        $this->assertContains('EUR', $currencies);
        $this->assertContains('AED', $currencies);
        $this->assertContains('AUD', $currencies);
    }

    /** @test */
    public function it_caches_rate_after_first_fetch()
    {
        CurrencyRate::create([
            'currency_code' => 'GBP',
            'exchange_rate' => 0.009200,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);

        // First call should hit DB and cache
        $rate1 = $this->exchangeRateService->getRate('GBP');
        $this->assertEquals(0.009200, $rate1);

        // Cache should now have it
        $this->assertTrue(Cache::has('currency_rate_GBP'));
    }

    /** @test */
    public function it_clears_cache_when_syncing()
    {
        CurrencyRate::create([
            'currency_code' => 'GBP',
            'exchange_rate' => 0.009200,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);

        $this->exchangeRateService->getRate('GBP');
        $this->assertTrue(Cache::has('currency_rate_GBP'));

        $this->exchangeRateService->clearCache();
        $this->assertFalse(Cache::has('currency_rate_GBP'));
    }

    /** @test */
    public function it_gets_all_rates_with_metadata()
    {
        CurrencyRate::create([
            'currency_code' => 'GBP',
            'exchange_rate' => 0.009200,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);

        $allRates = $this->exchangeRateService->getAllRates();

        $this->assertArrayHasKey('INR', $allRates);
        $this->assertArrayHasKey('GBP', $allRates);
        $this->assertTrue($allRates['INR']['is_base']);
        $this->assertFalse($allRates['GBP']['is_base']);
        $this->assertEquals(0.009200, $allRates['GBP']['exchange_rate']);
        $this->assertEquals('₹', $allRates['INR']['symbol']);
        $this->assertEquals('£', $allRates['GBP']['symbol']);
    }

    /** @test */
    public function it_returns_full_currency_context()
    {
        CurrencyRate::create([
            'currency_code' => 'GBP',
            'exchange_rate' => 0.009200,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);

        Cache::forget('currency_rate_GBP');

        $context = $this->currencyService->getCurrencyContext(null);

        $this->assertEquals('IN', $context['countryCode']);
        $this->assertEquals('INR', $context['currencyCode']);
        $this->assertEquals(1.0, $context['exchangeRate']);
        $this->assertEquals('₹', $context['currencySymbol']);
    }

    /** @test */
    public function it_converts_via_api_endpoint_correctly()
    {
        CurrencyRate::create([
            'currency_code' => 'GBP',
            'exchange_rate' => 0.009200,
            'base_currency' => 'INR',
            'source' => 'test',
            'fetched_at' => now(),
        ]);
        Cache::forget('currency_rate_GBP');

        $response = $this->postJson('/api/currency/convert', [
            'amount' => 100000,
            'currency' => 'GBP',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'inr_amount' => 100000,
                    'currency' => 'GBP',
                    'exchange_rate' => 0.0092,
                    'display_amount' => 920.0,
                ],
            ]);
    }

    /** @test */
    public function it_falls_back_to_baseline_rate_when_database_has_no_records()
    {
        $this->assertEquals(0, CurrencyRate::count());

        // Baseline rate for GBP is 0.0092
        $gbpRate = $this->exchangeRateService->getRate('GBP');
        $this->assertEquals(0.0092, $gbpRate);

        // Baseline rate for USD is 0.0116
        $usdRate = $this->exchangeRateService->getRate('USD');
        $this->assertEquals(0.0116, $usdRate);

        // Baseline rate for EUR is 0.0110
        $eurRate = $this->exchangeRateService->getRate('EUR');
        $this->assertEquals(0.0110, $eurRate);

        // Baseline rate for AED is 0.0426
        $aedRate = $this->exchangeRateService->getRate('AED');
        $this->assertEquals(0.0426, $aedRate);

        // Baseline conversion on empty DB
        $displayGbp = $this->exchangeRateService->convertToDisplay(100000, 'GBP');
        $this->assertEquals(920.00, $displayGbp);
    }

    /** @test */
    public function it_prioritizes_database_rate_over_baseline_fallback()
    {
        // Custom database rate differing from baseline (0.0092 -> 0.0090)
        CurrencyRate::create([
            'currency_code' => 'GBP',
            'exchange_rate' => 0.009000,
            'base_currency' => 'INR',
            'source' => 'test-override',
            'fetched_at' => now(),
        ]);
        Cache::forget('currency_rate_GBP');

        $rate = $this->exchangeRateService->getRate('GBP');
        $this->assertEquals(0.009000, $rate);

        $display = $this->exchangeRateService->convertToDisplay(98500, 'GBP');
        $this->assertEquals(886.50, $display);
    }

    /** @test */
    public function it_handles_invalid_or_zero_database_rate_by_falling_back_to_baseline()
    {
        CurrencyRate::create([
            'currency_code' => 'GBP',
            'exchange_rate' => 0.000000, // invalid zero rate in DB
            'base_currency' => 'INR',
            'source' => 'corrupted',
            'fetched_at' => now(),
        ]);
        Cache::forget('currency_rate_GBP');

        $rate = $this->exchangeRateService->getRate('GBP');
        $this->assertEquals(0.0092, $rate);
    }

    /** @test */
    public function it_returns_valid_currency_context_with_exchange_rate_on_empty_database()
    {
        $this->assertEquals(0, CurrencyRate::count());

        $response = $this->getJson('/api/currency/context?country_code=GB');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'countryCode' => 'GB',
                    'currencyCode' => 'GBP',
                    'exchangeRate' => 0.0092,
                    'currencySymbol' => '£',
                    'currencyLocale' => 'en-GB',
                ],
            ]);
    }
}
