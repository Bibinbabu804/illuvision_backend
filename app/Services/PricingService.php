<?php

namespace App\Services;

use App\Models\PricingPlan;
use Illuminate\Support\Facades\DB;
use App\Services\Campaign\ExchangeRateService;

class PricingService
{
    public function __construct(
        protected ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * Normalise the admin-entered base price to canonical INR.
     *
     * Returns an array of fields ready to merge into the plan create/update data:
     *   base_price             — canonical INR calculation amount
     *   base_price_original    — original admin-entered amount
     *   base_price_currency    — original currency code
     *   conversion_rate_to_inr — rate used (1 source = X INR)
     *
     * For INR input, no conversion occurs and rate=1.
     * For foreign currencies, rate is fetched from ExchangeRateService.
     *
     * @throws \RuntimeException if rate unavailable for a foreign currency
     */
    private function normalizeBasePrice(float $originalAmount, string $currency): array
    {
        $currency = strtoupper($currency);

        if ($currency === 'INR') {
            return [
                'base_price'             => round($originalAmount, 2),
                'base_price_original'    => round($originalAmount, 2),
                'base_price_currency'    => 'INR',
                'conversion_rate_to_inr' => 1.0,
            ];
        }

        // Fetch rate from the authoritative ExchangeRateService (DB/cache backed).
        // Never trust a rate supplied by the frontend.
        $rateToInr = $this->exchangeRateService->getRateToInr($currency);

        if ($rateToInr === null || $rateToInr <= 0) {
            throw new \RuntimeException(
                "Exchange rate unavailable for currency '{$currency}'. " .
                "Cannot normalise Pricing Plan base price to INR. " .
                "Ensure exchange rates are synced (Admin → Currency Rates → Sync) " .
                "or select INR as the plan currency."
            );
        }

        $canonicalInr = round($originalAmount * $rateToInr, 2);

        return [
            'base_price'             => $canonicalInr,
            'base_price_original'    => round($originalAmount, 2),
            'base_price_currency'    => $currency,
            'conversion_rate_to_inr' => round($rateToInr, 8),
        ];
    }

    /**
     * Store a new pricing plan and its rules atomically in a database transaction.
     */
    public function store(array $data): PricingPlan
    {
        return DB::transaction(function () use ($data) {
            // Handle default plan flag
            $isDefault = filter_var($data['is_default'] ?? $data['isDefault'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isDefault) {
                PricingPlan::query()->update(['is_default' => false]);
            }

            // Normalise base_price to canonical INR.
            // This is the single point of currency conversion for Pricing Plans.
            $originalAmount = (float) ($data['base_price'] ?? $data['basePrice'] ?? 0.00);
            $currency = strtoupper($data['currency'] ?? 'INR');
            $priceFields = $this->normalizeBasePrice($originalAmount, $currency);

            // Create plan
            $plan = PricingPlan::create([
                'name'                   => $data['name'],
                'description'            => $data['description'] ?? null,
                'currency'               => $currency,
                'minimum_duration'       => $data['minimum_duration'] ?? $data['minDuration'] ?? 1,
                'minimum_duration_type'  => $data['minimum_duration_type'] ?? $data['minDurationType'] ?? 'Hour',
                'is_default'             => $isDefault,
                'is_active'              => filter_var($data['is_active'] ?? $data['isActive'] ?? true, FILTER_VALIDATE_BOOLEAN),
                // Normalised price fields (replaces the raw base_price from $data)
                ...$priceFields,
            ]);

            // Save sub-rules
            $this->saveRelations($plan, $data);

            return $plan->load(['screenRules', 'durationRules', 'peakRules', 'weekendRules']);
        });
    }

    /**
     * Update an existing pricing plan and its rules atomically in a database transaction.
     */
    public function update(PricingPlan $plan, array $data): PricingPlan
    {
        return DB::transaction(function () use ($plan, $data) {
            // Handle default plan flag
            $isDefault = filter_var($data['is_default'] ?? $data['isDefault'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($isDefault) {
                PricingPlan::query()->where('id', '!=', $plan->id)->update(['is_default' => false]);
            }

            // Re-normalise whenever the plan is updated, using the CURRENT submitted values.
            // This correctly handles price changes (£100→£120) and currency changes (GBP→USD).
            // The source amount is always the admin-entered original, never the stored INR value.
            $submittedPrice = $data['base_price'] ?? $data['basePrice'] ?? null;
            $originalAmount = ($submittedPrice !== null)
                ? (float) $submittedPrice
                : (float) ($plan->base_price_original ?? $plan->base_price);

            $currency = strtoupper(
                $data['currency'] ?? $plan->currency ?? 'INR'
            );

            $priceFields = $this->normalizeBasePrice($originalAmount, $currency);

            // Update plan
            $plan->update([
                'name'                  => $data['name'] ?? $plan->name,
                'description'           => $data['description'] ?? $plan->description,
                'currency'              => $currency,
                'minimum_duration'      => $data['minimum_duration'] ?? $data['minDuration'] ?? $plan->minimum_duration,
                'minimum_duration_type' => $data['minimum_duration_type'] ?? $data['minDurationType'] ?? $plan->minimum_duration_type,
                'is_default'            => $isDefault,
                'is_active'             => filter_var($data['is_active'] ?? $data['isActive'] ?? $plan->is_active, FILTER_VALIDATE_BOOLEAN),
                // Normalised price fields
                ...$priceFields,
            ]);

            // Save sub-rules
            $this->saveRelations($plan, $data);

            return $plan->load(['screenRules', 'durationRules', 'peakRules', 'weekendRules']);
        });
    }

    /**
     * Delete and recreate associated rules for simplicity and transaction safety.
     */
    protected function saveRelations(PricingPlan $plan, array $data): void
    {
        // 1. Screens
        $plan->screenRules()->delete();
        $screenRules = $data['screen_rules'] ?? $data['screenRules'] ?? [];
        foreach ($screenRules as $rule) {
            $plan->screenRules()->create([
                'minimum_screens' => $rule['minimum_screens'] ?? $rule['minimumScreens'] ?? 1,
                'maximum_screens' => $rule['maximum_screens'] ?? $rule['maximumScreens'] ?? null,
                'discount_type' => $rule['discount_type'] ?? $rule['discountType'] ?? 'Percentage',
                'discount_value' => $rule['discount_value'] ?? $rule['discountValue'] ?? 0.00,
                'sort_order' => $rule['sort_order'] ?? $rule['sortOrder'] ?? 0,
                'status' => $rule['status'] ?? 'active',
            ]);
        }

        // 2. Durations
        $plan->durationRules()->delete();
        $durationRules = $data['duration_rules'] ?? $data['durationRules'] ?? [];
        foreach ($durationRules as $rule) {
            $plan->durationRules()->create([
                'minimum_duration' => $rule['minimum_duration'] ?? $rule['minimumDuration'] ?? 1,
                'maximum_duration' => $rule['maximum_duration'] ?? $rule['maximumDuration'] ?? null,
                'duration_unit' => $rule['duration_unit'] ?? $rule['durationUnit'] ?? 'Month',
                'discount_type' => $rule['discount_type'] ?? $rule['discountType'] ?? 'Percentage',
                'discount_value' => $rule['discount_value'] ?? $rule['discountValue'] ?? 0.00,
                'sort_order' => $rule['sort_order'] ?? $rule['sortOrder'] ?? 0,
                'status' => $rule['status'] ?? 'active',
            ]);
        }

        // 3. Peaks
        $plan->peakRules()->delete();
        $peakRules = $data['peak_rules'] ?? $data['peakRules'] ?? [];
        foreach ($peakRules as $rule) {
            $plan->peakRules()->create([
                'day_of_week' => $rule['day_of_week'] ?? $rule['dayOfWeek'] ?? 'All',
                'start_time' => $rule['start_time'] ?? $rule['startTime'] ?? '18:00',
                'end_time' => $rule['end_time'] ?? $rule['endTime'] ?? '22:00',
                'pricing_type' => $rule['pricing_type'] ?? $rule['pricingType'] ?? 'multiplier',
                'value' => $rule['value'] ?? $rule['multiplier'] ?? 1.00,
                'status' => $rule['status'] ?? 'active',
            ]);
        }

        // 4. Weekends
        $plan->weekendRules()->delete();
        $weekendRules = $data['weekend_rules'] ?? $data['weekendRules'] ?? [];
        foreach ($weekendRules as $rule) {
            $plan->weekendRules()->create([
                'days' => $rule['days'] ?? 'Sat,Sun',
                'pricing_type' => $rule['pricing_type'] ?? $rule['pricingType'] ?? 'multiplier',
                'value' => $rule['value'] ?? $rule['multiplier'] ?? 1.00,
                'status' => $rule['status'] ?? 'active',
            ]);
        }
    }
}
