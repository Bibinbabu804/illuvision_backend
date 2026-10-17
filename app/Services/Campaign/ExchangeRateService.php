<?php

namespace App\Services\Campaign;

use App\Infrastructure\ExchangeRates\Contracts\ExchangeRateApiClientInterface;
use App\Models\CurrencyRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ExchangeRateService
 *
 * Fetches exchange rates from ExchangeRate-API (free, no key needed).
 * Stores rates in the currency_rates table and caches aggressively.
 * Never called during booking requests — rates are pre-synced via scheduler.
 */
class ExchangeRateService
{
    protected ExchangeRateApiClientInterface $apiClient;

    public function __construct(?ExchangeRateApiClientInterface $apiClient = null)
    {
        $this->apiClient = $apiClient ?? app(ExchangeRateApiClientInterface::class);
    }

    /**
     * Base URL for ExchangeRate-API (free tier).
     * Uses INR as the base currency.
     */
    protected string $apiUrl = 'https://api.exchangerate-api.com/v4/latest/INR';

    /**
     * Cache TTL in seconds (12 hours = 43200 seconds).
     */
    protected int $cacheTtl = 43200;

    /**
     * The supported display currencies (future-ready).
     */
    public array $supportedCurrencies = ['INR', 'GBP', 'USD', 'EUR', 'AED', 'AUD'];

    /**
     * Currency metadata: symbol, name, decimal places.
     */
    public array $currencyMeta = [
        'INR' => ['symbol' => '₹', 'name' => 'Indian Rupee', 'decimals' => 0, 'locale' => 'en-IN'],
        'GBP' => ['symbol' => '£', 'name' => 'British Pound', 'decimals' => 2, 'locale' => 'en-GB'],
        'USD' => ['symbol' => '$', 'name' => 'US Dollar', 'decimals' => 2, 'locale' => 'en-US'],
        'EUR' => ['symbol' => '€', 'name' => 'Euro', 'decimals' => 2, 'locale' => 'de-DE'],
        'AED' => ['symbol' => 'د.إ', 'name' => 'UAE Dirham', 'decimals' => 2, 'locale' => 'ar-AE'],
        'AUD' => ['symbol' => 'A$', 'name' => 'Australian Dollar', 'decimals' => 2, 'locale' => 'en-AU'],
    ];

    /**
     * Authoritative baseline exchange rates against INR (1 INR = X target currency).
     * Used when database rates are not yet seeded or synced.
     */
    public array $baselineRates = [
        'INR' => 1.0000,
        'GBP' => 0.0092,
        'USD' => 0.0116,
        'EUR' => 0.0110,
        'AED' => 0.0426,
        'AUD' => 0.0180,
    ];

    /**
     * Country -> Currency mapping for IP-based detection.
     */
    public array $countryCurrencyMap = [
        'IN' => 'INR',
        'GB' => 'GBP',
        'US' => 'USD',
        'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'NL' => 'EUR',
        'BE' => 'EUR', 'AT' => 'EUR', 'PT' => 'EUR', 'IE' => 'EUR', 'FI' => 'EUR',
        'GR' => 'EUR', 'LU' => 'EUR', 'SK' => 'EUR', 'SI' => 'EUR', 'EE' => 'EUR',
        'LV' => 'EUR', 'LT' => 'EUR', 'CY' => 'EUR', 'MT' => 'EUR', 'HR' => 'EUR',
        'AE' => 'AED',
        'AU' => 'AUD',
    ];

    /**
     * Fetch latest rates from ExchangeRate-API and store them.
     *
     * @return array{fetched: int, currencies: array}
     */
    public function syncRates(): array
    {
        try {
            $rates = $this->apiClient->fetchRates($this->apiUrl);

            if (empty($rates)) {
                return ['fetched' => 0, 'currencies' => []];
            }

            $fetchedCurrencies = [];

            foreach ($this->supportedCurrencies as $code) {
                if ($code === 'INR') continue; // Base currency — always 1

                $rate = $rates[$code] ?? null;
                if ($rate === null || $rate <= 0) {
                    Log::warning("Exchange rate not found for {$code}");
                    continue;
                }

                CurrencyRate::updateOrCreate(
                    [
                        'currency_code' => $code,
                        'base_currency' => 'INR',
                    ],
                    [
                        'exchange_rate' => $rate,
                        'source'        => 'exchangerate-api',
                        'fetched_at'    => now(),
                    ]
                );

                $fetchedCurrencies[] = $code;
            }

            // Clear the cache so next request picks up fresh rates
            $this->clearCache();

            Log::info('Exchange rates synced successfully', [
                'currencies' => $fetchedCurrencies,
            ]);

            return [
                'fetched' => count($fetchedCurrencies),
                'currencies' => $fetchedCurrencies,
            ];

        } catch (\Exception $e) {
            Log::error('Exchange rate sync failed: ' . $e->getMessage());
            return ['fetched' => 0, 'currencies' => []];
        }
    }

    /**
     * Get the exchange rate for a currency against INR.
     * Hierarchy:
     * 1. INR is always 1.0.
     * 2. Database exchange rate if valid (> 0).
     * 3. Static baseline rate fallback for supported currencies.
     * 4. Returns null for unsupported currencies.
     */
    public function getRate(string $currencyCode): ?float
    {
        $currencyCode = strtoupper($currencyCode);

        if ($currencyCode === 'INR') {
            return 1.0;
        }

        if (!in_array($currencyCode, $this->supportedCurrencies, true)) {
            return null;
        }

        $cacheKey = "currency_rate_{$currencyCode}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($currencyCode) {
            $rate = CurrencyRate::forCurrency($currencyCode)->first();
            if ($rate && (float) $rate->exchange_rate > 0) {
                return (float) $rate->exchange_rate;
            }
            return $this->baselineRates[$currencyCode] ?? null;
        });
    }

    /**
     * Get all active rates (for admin display).
     */
    public function getAllRates(): array
    {
        $rates = CurrencyRate::where('base_currency', 'INR')->get()->keyBy('currency_code');
        $result = [];

        foreach ($this->supportedCurrencies as $code) {
            if ($code === 'INR') {
                $result[$code] = [
                    'currency_code'   => 'INR',
                    'exchange_rate'   => 1.0,
                    'symbol'          => $this->currencyMeta['INR']['symbol'],
                    'name'            => $this->currencyMeta['INR']['name'],
                    'is_base'         => true,
                    'fetched_at'      => now()->toISOString(),
                ];
                continue;
            }

            $rate = $rates->get($code);
            $rateValue = ($rate && (float) $rate->exchange_rate > 0)
                ? (float) $rate->exchange_rate
                : ($this->baselineRates[$code] ?? null);
            $source = ($rate && (float) $rate->exchange_rate > 0)
                ? ($rate->source ?? 'database')
                : 'baseline-fallback';
            $meta = $this->currencyMeta[$code] ?? ['symbol' => $code, 'name' => $code, 'decimals' => 2, 'locale' => 'en-US'];

            $result[$code] = [
                'currency_code'   => $code,
                'exchange_rate'   => $rateValue,
                'symbol'          => $meta['symbol'],
                'name'            => $meta['name'],
                'decimals'        => $meta['decimals'],
                'locale'          => $meta['locale'],
                'is_base'         => false,
                'source'          => $source,
                'fetched_at'      => $rate?->fetched_at?->toISOString() ?? now()->toISOString(),
            ];
        }

        return $result;
    }

    /**
     * Determine the display currency for a given country code.
     */
    public function currencyForCountry(string $countryCode): string
    {
        return $this->countryCurrencyMap[strtoupper($countryCode)] ?? 'INR';
    }

    /**
     * Check whether a currency code is supported by this service.
     */
    public function isSupportedCurrency(string $currencyCode): bool
    {
        return in_array(strtoupper($currencyCode), $this->supportedCurrencies, true);
    }

    /**
     * Get the exchange rate from a source currency to INR.
     *
     * Returns: how many INR equal 1 unit of $sourceCurrency.
     * e.g. getRateToInr('GBP') ≈ 108.70  (1 GBP = ₹108.70)
     *
     * This is the inverse of getRate(), which returns 1 INR in the target currency.
     *
     * @param string $sourceCurrency  ISO 4217 code (e.g. 'GBP', 'USD')
     * @return float|null  INR per 1 unit of source, or null if rate unavailable/unsupported
     */
    public function getRateToInr(string $sourceCurrency): ?float
    {
        $sourceCurrency = strtoupper($sourceCurrency);

        // INR → INR is always 1
        if ($sourceCurrency === 'INR') {
            return 1.0;
        }

        // getRate() returns (1 INR = X source), so the inverse is (1 source = 1/X INR)
        $rateInrToSource = $this->getRate($sourceCurrency);

        if ($rateInrToSource === null || $rateInrToSource <= 0) {
            return null;
        }

        return 1.0 / $rateInrToSource;
    }

    /**
     * Convert a foreign-currency amount to canonical INR.
     *
     * Example: convertToInr(100.0, 'GBP') → ₹10,869.57 (if GBP rate = 0.0092)
     *
     * @param float  $foreignAmount   Amount in source currency
     * @param string $sourceCurrency  ISO 4217 code of the source
     * @return float  Canonical INR amount, rounded to 2 decimal places
     *
     * @throws \RuntimeException if the exchange rate is unavailable (prevents silent bad data)
     */
    public function convertToInr(float $foreignAmount, string $sourceCurrency): float
    {
        $sourceCurrency = strtoupper($sourceCurrency);

        if ($sourceCurrency === 'INR') {
            return round($foreignAmount, 2);
        }

        $rateToInr = $this->getRateToInr($sourceCurrency);

        if ($rateToInr === null || $rateToInr <= 0) {
            throw new \RuntimeException(
                "Exchange rate unavailable for currency '{$sourceCurrency}'. " .
                "Cannot normalise Pricing Plan base price to INR. " .
                "Ensure exchange rates are synced or select a supported currency."
            );
        }

        return round($foreignAmount * $rateToInr, 2);
    }

    /**
     * Convert an INR amount to the display currency.
     */
    public function convertToDisplay(float $inrAmount, string $currencyCode): float
    {
        $rate = $this->getRate($currencyCode);
        if ($rate === null || $rate <= 0) {
            return $inrAmount; // Fallback to INR
        }
        return round($inrAmount * $rate, 2);
    }

    /**
     * Clear cached rates.
     */
    public function clearCache(): void
    {
        foreach ($this->supportedCurrencies as $code) {
            Cache::forget("currency_rate_{$code}");
        }
        Cache::forget('currency_all_rates');
    }
}
