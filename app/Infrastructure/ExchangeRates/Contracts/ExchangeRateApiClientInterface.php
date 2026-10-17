<?php

namespace App\Infrastructure\ExchangeRates\Contracts;

/**
 * ExchangeRateApiClientInterface
 *
 * Defines the contract for fetching foreign currency rates from external exchange providers.
 */
interface ExchangeRateApiClientInterface
{
    /**
     * Fetch latest currency rates from external provider.
     *
     * @param string $url
     * @return array<string, float> Map of currency code to rate against base currency
     */
    public function fetchRates(string $url): array;
}
