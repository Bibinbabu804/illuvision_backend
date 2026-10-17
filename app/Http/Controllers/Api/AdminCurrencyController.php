<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Campaign\CurrencyService;
use Illuminate\Http\Request;

/**
 * AdminCurrencyController
 *
 * Admin-only endpoints for viewing and managing currency exchange rates.
 * Rates are API-driven — no manual entry allowed.
 */
class AdminCurrencyController extends Controller
{
    public function __construct(
        protected CurrencyService $currencyService
    ) {}

    /**
     * GET /api/admin/currency-rates
     *
     * Get all exchange rates with metadata for admin display.
     */
    public function index()
    {
        $rates = $this->currencyService->getAllRates();

        return response()->json([
            'status' => true,
            'message' => 'Currency rates retrieved',
            'data' => [
                'rates' => $rates,
                'supported_currencies' => $this->currencyService->getSupportedCurrencies(),
                'base_currency' => 'INR',
                'last_sync' => $this->getLastSyncInfo(),
            ],
        ]);
    }

    /**
     * POST /api/admin/currency-rates/sync
     *
     * Manually trigger an exchange rate sync.
     */
    public function sync()
    {
        $result = $this->currencyService->syncRates();

        if ($result['fetched'] > 0) {
            return response()->json([
                'status' => true,
                'message' => "Exchange rates synced successfully. {$result['fetched']} currencies updated.",
                'data' => $result,
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Failed to sync exchange rates. Please try again later.',
            'data' => $result,
        ], 500);
    }

    /**
     * Get the last sync timestamp from the currency_rates table.
     */
    protected function getLastSyncInfo(): array
    {
        $latest = \App\Models\CurrencyRate::where('base_currency', 'INR')
            ->orderByDesc('fetched_at')
            ->first();

        return [
            'last_synced_at' => $latest?->fetched_at?->toISOString(),
            'source' => $latest?->source ?? 'exchangerate-api',
            'next_sync_at' => now()->addHours(12)->toISOString(),
        ];
    }
}
