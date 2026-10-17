<?php

namespace App\Console\Commands;
use App\Services\Campaign\ExchangeRateService;
use Illuminate\Console\Command;

/**
 * SyncExchangeRates
 *
 * Scheduled command that fetches the latest exchange rates from ExchangeRate-API
 * and stores them in the currency_rates table.
 *
 * Schedule: every 12 hours (or daily).
 * Add to routes/console.php:
 *   Schedule::command('currency:sync-rates')->twiceDaily(1, 13);
 */
class SyncExchangeRates extends Command
{
    protected $signature = 'currency:sync-rates';
    protected $description = 'Fetch and store latest exchange rates from ExchangeRate-API';

    public function __construct(
        protected ExchangeRateService $exchangeRateService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Syncing exchange rates...');

        $result = $this->exchangeRateService->syncRates();

        if ($result['fetched'] > 0) {
            $this->info("Successfully synced {$result['fetched']} currencies: " . implode(', ', $result['currencies']));
            return Command::SUCCESS;
        }

        $this->error('Failed to sync exchange rates. Check the logs for details.');
        return Command::FAILURE;
    }
}
