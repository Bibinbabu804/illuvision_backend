<?php

namespace App\Services\Campaign;

use Illuminate\Support\Facades\Cache;

/**
 * CurrencyService
 *
 * High-level orchestrator for currency detection, rate retrieval, and display conversion.
 * Wraps ExchangeRateService + CountryDetectionService for convenience.
 * All caching is delegated to ExchangeRateService.
 */
class CurrencyService
{
    public function __construct(
        protected ExchangeRateService $exchangeRateService,
        protected CountryDetectionService $countryDetectionService,
    ) {}

    /**
     * Get the full currency context for a visitor.
     *
     * Priority:
     * 1. Use provided $countryCode (from frontend region detection)
     * 2. Fall back to IP-based detection via CountryDetectionService
     *
     * @param string|null $ip           Client IP address (used as fallback).
     * @param string|null $countryCode  Country code provided by frontend (primary).
     * @return array{countryCode: string, currencyCode: string, exchangeRate: float|null, currencySymbol: string, currencyDecimals: int, currencyLocale: string, detectionSource: string}
     */
    public function getCurrencyContext(?string $ip = null, ?string $countryCode = null): array
    {
        $detectionSource = 'backend-ip';

        if ($countryCode) {
            // Frontend provided the country code — use it directly
            $detectionSource = 'frontend-region';
            \Log::info("CurrencyService: Using frontend-provided country code", [
                'countryCode' => $countryCode,
                'detectionSource' => $detectionSource,
            ]);
        } else {
            // Fall back to IP-based detection
            $countryCode = $this->countryDetectionService->detect($ip);
            \Log::info("CurrencyService: Using IP-based country detection", [
                'ip' => $ip,
                'countryCode' => $countryCode,
                'detectionSource' => $detectionSource,
            ]);
        }

        $currencyCode = $this->exchangeRateService->currencyForCountry($countryCode);
        $rate = $this->exchangeRateService->getRate($currencyCode);
        $meta = $this->exchangeRateService->currencyMeta[$currencyCode] ?? [
            'symbol' => $currencyCode,
            'name' => $currencyCode,
            'decimals' => 2,
            'locale' => 'en-US',
        ];

        \Log::info("CurrencyService: Resolved currency", [
            'countryCode' => $countryCode,
            'currencyCode' => $currencyCode,
            'exchangeRate' => $rate,
            'detectionSource' => $detectionSource,
        ]);

        return [
            'countryCode'       => $countryCode,
            'currencyCode'      => $currencyCode,
            'exchangeRate'      => $rate,
            'currencySymbol'    => $meta['symbol'],
            'currencyDecimals'  => $meta['decimals'],
            'currencyLocale'    => $meta['locale'],
            'detectionSource'   => $detectionSource,
        ];
    }

    /**
     * Convert an INR amount to a display amount in the given currency.
     */
    public function convertToDisplay(float $inrAmount, string $currencyCode): float
    {
        return $this->exchangeRateService->convertToDisplay($inrAmount, $currencyCode);
    }

    /**
     * Get exchange rate for a currency.
     */
    public function getRate(string $currencyCode): ?float
    {
        return $this->exchangeRateService->getRate($currencyCode);
    }

    /**
     * Get all rates with metadata.
     */
    public function getAllRates(): array
    {
        return $this->exchangeRateService->getAllRates();
    }

    /**
     * Sync rates from the exchange API.
     */
    public function syncRates(): array
    {
        return $this->exchangeRateService->syncRates();
    }

    /**
     * Get supported currencies with metadata.
     */
    public function getSupportedCurrencies(): array
    {
        $result = [];
        foreach ($this->exchangeRateService->supportedCurrencies as $code) {
            $meta = $this->exchangeRateService->currencyMeta[$code] ?? [];
            $result[$code] = $meta;
        }
        return $result;
    }
}
