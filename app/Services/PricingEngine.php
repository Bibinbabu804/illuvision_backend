<?php

namespace App\Services;

use App\Models\Screen;
use Carbon\Carbon;

class PricingEngine
{
    public function __construct(
        protected CampaignPricingService $campaignPricingService
    ) {}

    /**
     * Calculate multi-asset campaign pricing preview.
     * @param array $params [
     *   'items' => [['asset_id' => int, 'quantity' => int]],
     *   'start_date' => 'Y-m-d',
     *   'end_date' => 'Y-m-d',
     *   'daily_start_time' => '00:00',
     *   'daily_end_time' => '23:59'
     * ]
     */
    public function calculatePreview(array $params): array
    {
        $items = $params['items'] ?? [];
        $startDate = $params['start_date'];
        $endDate = $params['end_date'];

        $days = Carbon::parse($startDate)->startOfDay()
            ->diffInDays(Carbon::parse($endDate)->startOfDay()) + 1;

        $lineItems = [];
        $grossTotal = 0.0;

        foreach ($items as $item) {
            $assetId = $item['asset_id'];
            $quantity = max(1, (int) ($item['quantity'] ?? 1));

            $asset = Screen::with('pricingPlan')->find($assetId);
            if (!$asset) continue;

            $basePrice = $asset->pricingPlan?->base_price ? (float) $asset->pricingPlan->base_price : 1000.0;
            $unitType = strtolower($asset->pricingPlan?->minimum_duration_type ?? 'day');
            $unitPricePerDay = match ($unitType) {
                'month' => $basePrice / 30.0,
                'week' => $basePrice / 7.0,
                'hour' => $basePrice * 24.0,
                'minute' => $basePrice * 1440.0,
                default => $basePrice,
            };

            $lineSubtotal = round($unitPricePerDay * $quantity * $days, 2);
            $grossTotal += $lineSubtotal;

            $lineItems[] = [
                'asset_id' => $asset->id,
                'asset_name' => $asset->name,
                'asset_type' => $asset->asset_type?->value ?? 'fixed',
                'quantity' => $quantity,
                'unit_price_per_day' => round($unitPricePerDay, 2),
                'days' => $days,
                'line_subtotal' => $lineSubtotal,
            ];
        }

        // Apply duration volume discount (10% off for >= 30 days)
        $durationDiscount = $days >= 30 ? round($grossTotal * 0.10, 2) : 0.0;
        $finalTotal = max(0.0, $grossTotal - $durationDiscount);

        return [
            'summary' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_days' => $days,
                'total_items' => count($lineItems),
            ],
            'line_items' => $lineItems,
            'pricing' => [
                'gross_total' => round($grossTotal, 2),
                'duration_discount' => $durationDiscount,
                'final_total' => round($finalTotal, 2),
            ],
        ];
    }
}
