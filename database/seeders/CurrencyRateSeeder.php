<?php

namespace Database\Seeders;

use App\Models\CurrencyRate;
use App\Services\Campaign\ExchangeRateService;
use Illuminate\Database\Seeder;

class CurrencyRateSeeder extends Seeder
{
    /**
     * Seed baseline currency exchange rates.
     */
    public function run(): void
    {
        $service = app(ExchangeRateService::class);
        $baselineRates = $service->baselineRates;

        foreach ($baselineRates as $currencyCode => $rate) {
            if ($currencyCode === 'INR') {
                continue;
            }

            CurrencyRate::updateOrCreate(
                [
                    'currency_code' => $currencyCode,
                    'base_currency' => 'INR',
                ],
                [
                    'exchange_rate' => $rate,
                    'source'        => 'baseline-seeder',
                    'fetched_at'    => now(),
                ]
            );
        }
    }
}
