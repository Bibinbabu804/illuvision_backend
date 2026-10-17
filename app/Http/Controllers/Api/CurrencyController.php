<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Campaign\CurrencyService;
use Illuminate\Http\Request;

/**
 * CurrencyController
 *
 * Public API endpoints for currency detection and exchange rate retrieval.
 * All rates are pre-synced — no live API calls during booking requests.
 */
class CurrencyController extends Controller
{
    public function __construct(
        protected CurrencyService $currencyService
    ) {}

    /**
     * GET /api/currency/context
     *
     * Detect the visitor's currency context.
     *
     * Country detection priority:
     *   1. X-Country-Code header (from frontend region detection / ipapi.co)
     *   2. ?country_code query param (for local dev/testing)
     *   3. Fallback: backend IP detection via $request->ip()
     *
     * Frontend calls this once on page load and caches the result.
     */
    public function context(Request $request)
    {
        // Priority 1 & 2: Frontend-provided country code (header or query param)
        $countryCode = $request->header('X-Country-Code')
                     ?? $request->query('country_code');

        $ip = $request->ip();
       

        if ($countryCode) {
            \Log::info('CurrencyController: Using frontend-provided country code', [
                'countryCode' => strtoupper($countryCode),
                'source' => $request->header('X-Country-Code') ? 'X-Country-Code header' : 'country_code query param',
                'visitorIp' => $ip,
            ]);
        } else {
            \Log::info('CurrencyController: No frontend country code, falling back to IP detection', [
                'visitorIp' => $ip,
            ]);
        }

        // Pass both ip (for fallback) and countryCode (for primary) to service
        $context = $this->currencyService->getCurrencyContext($ip, $countryCode ? strtoupper($countryCode) : null);

        return response()->json([
            'status' => true,
            'message' => 'Currency context detected',
            'data' => $context,
        ]);
    }

    /**
     * GET /api/currencies
     *
     * List all supported currencies with metadata.
     */
    public function currencies()
    {
        $currencies = $this->currencyService->getSupportedCurrencies();

        // Also include rates
        $rates = $this->currencyService->getAllRates();

        return response()->json([
            'status' => true,
            'message' => 'Supported currencies',
            'data' => [
                'currencies' => $currencies,
                'rates' => $rates,
            ],
        ]);
    }

    /**
     * GET /api/currency/rates
     *
     * Get all current exchange rates against INR.
     */
    public function rates()
    {
        $rates = $this->currencyService->getAllRates();

        return response()->json([
            'status' => true,
            'message' => 'Exchange rates',
            'data' => $rates,
        ]);
    }

    /**
     * POST /api/currency/convert
     *
     * Convert an INR amount to a display currency.
     * Used for preview purposes only — actual booking conversion happens on creation.
     */
    public function convert(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
        ]);

        $amount = (float) $request->input('amount');
        $currency = strtoupper($request->input('currency'));
        $rate = $this->currencyService->getRate($currency);
        $displayAmount = $this->currencyService->convertToDisplay($amount, $currency);

        return response()->json([
            'status' => true,
            'message' => 'Amount converted',
            'data' => [
                'inr_amount' => $amount,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'display_amount' => $displayAmount,
            ],
        ]);
    }
}
