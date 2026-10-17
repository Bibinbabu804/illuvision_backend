<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PricingPlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [
            'name' => $this->input('name'),
            'description' => $this->input('description'),
            'base_price' => $this->input('base_price') ?? $this->input('basePrice'),
            'currency' => strtoupper($this->input('currency') ?? 'INR'),
            'minimum_duration' => $this->input('minimum_duration') ?? $this->input('minDuration') ?? 1,
            'minimum_duration_type' => $this->input('minimum_duration_type') ?? $this->input('minDurationType') ?? 'Hour',
            'is_default' => filter_var($this->input('is_default') ?? $this->input('isDefault') ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_active' => filter_var($this->input('is_active') ?? $this->input('isActive') ?? true, FILTER_VALIDATE_BOOLEAN),
        ];

        // 2. Normalize screen_rules
        $screens = $this->input('screen_rules') ?? $this->input('screenRules');
        if (is_array($screens)) {
            $normalized['screen_rules'] = [];
            foreach ($screens as $rule) {
                if (is_array($rule)) {
                    $max = $rule['maximum_screens'] ?? $rule['maximumScreens'] ?? null;
                    if ($max === '') {
                        $max = null;
                    }
                    $normalized['screen_rules'][] = [
                        'minimum_screens' => $rule['minimum_screens'] ?? $rule['minimumScreens'] ?? 1,
                        'maximum_screens' => $max,
                        'discount_type' => $rule['discount_type'] ?? $rule['discountType'] ?? 'Percentage',
                        'discount_value' => $rule['discount_value'] ?? $rule['discountValue'] ?? 0.00,
                        'sort_order' => $rule['sort_order'] ?? $rule['sortOrder'] ?? 0,
                        'status' => $rule['status'] ?? 'active',
                    ];
                }
            }
        }

        // 3. Normalize duration_rules
        $durations = $this->input('duration_rules') ?? $this->input('durationRules');
        if (is_array($durations)) {
            $normalized['duration_rules'] = [];
            foreach ($durations as $rule) {
                if (is_array($rule)) {
                    $max = $rule['maximum_duration'] ?? $rule['maximumDuration'] ?? null;
                    if ($max === '') {
                        $max = null;
                    }
                    $normalized['duration_rules'][] = [
                        'minimum_duration' => $rule['minimum_duration'] ?? $rule['minimumDuration'] ?? 1,
                        'maximum_duration' => $max,
                        'duration_unit' => $rule['duration_unit'] ?? $rule['durationUnit'] ?? 'Month',
                        'discount_type' => $rule['discount_type'] ?? $rule['discountType'] ?? 'Percentage',
                        'discount_value' => $rule['discount_value'] ?? $rule['discountValue'] ?? 0.00,
                        'sort_order' => $rule['sort_order'] ?? $rule['sortOrder'] ?? 0,
                        'status' => $rule['status'] ?? 'active',
                    ];
                }
            }
        }

        // 4. Normalize peak_rules
        $peaks = $this->input('peak_rules') ?? $this->input('peakRules');
        if (is_array($peaks)) {
            $normalized['peak_rules'] = [];
            foreach ($peaks as $rule) {
                if (is_array($rule)) {
                    $normalized['peak_rules'][] = [
                        'day_of_week' => $rule['day_of_week'] ?? $rule['dayOfWeek'] ?? 'All',
                        'start_time' => $rule['start_time'] ?? $rule['startTime'] ?? '18:00',
                        'end_time' => $rule['end_time'] ?? $rule['endTime'] ?? '22:00',
                        'pricing_type' => $rule['pricing_type'] ?? $rule['pricingType'] ?? 'multiplier',
                        'value' => $rule['value'] ?? $rule['multiplier'] ?? 1.00,
                        'status' => $rule['status'] ?? 'active',
                    ];
                }
            }
        }

        // 5. Normalize weekend_rules
        $weekends = $this->input('weekend_rules') ?? $this->input('weekendRules');
        if (is_array($weekends)) {
            $normalized['weekend_rules'] = [];
            foreach ($weekends as $rule) {
                if (is_array($rule)) {
                    $normalized['weekend_rules'][] = [
                        'days' => $rule['days'] ?? 'Sat,Sun',
                        'pricing_type' => $rule['pricing_type'] ?? $rule['pricingType'] ?? 'multiplier',
                        'value' => $rule['value'] ?? $rule['multiplier'] ?? 1.00,
                        'status' => $rule['status'] ?? 'active',
                    ];
                }
            }
        }

        $this->replace($normalized);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'base_price' => 'sometimes|nullable|numeric|min:0',
            // Must be one of the currencies supported by ExchangeRateService.
            // This ensures a conversion rate is always available at save-time.
            'currency' => 'nullable|string|in:INR,GBP,USD,EUR,AED,AUD',
            'minimum_duration' => 'sometimes|required|integer|min:1',
            'minimum_duration_type' => 'sometimes|required|string|in:Minute,Hour,Day,Week,Month,minute,hour,day,week,month',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',

            // Screen Rules
            'screen_rules' => 'nullable|array',
            'screen_rules.*.minimum_screens' => 'required|integer|min:1',
            'screen_rules.*.maximum_screens' => 'nullable|integer|min:1',
            'screen_rules.*.discount_type' => 'required|string|in:Percentage,Fixed',
            'screen_rules.*.discount_value' => 'required|numeric|min:0',
            'screen_rules.*.sort_order' => 'nullable|integer|min:0',
            'screen_rules.*.status' => 'nullable|string',

            // Duration Rules
            'duration_rules' => 'nullable|array',
            'duration_rules.*.minimum_duration' => 'required|numeric|min:0.1',
            'duration_rules.*.maximum_duration' => 'nullable|numeric|min:0.1',
            'duration_rules.*.duration_unit' => 'required|string|in:Minute,Hour,Day,Week,Month,minute,hour,day,week,month',
            'duration_rules.*.discount_type' => 'required|string|in:Percentage,Fixed',
            'duration_rules.*.discount_value' => 'required|numeric|min:0',
            'duration_rules.*.sort_order' => 'nullable|integer|min:0',
            'duration_rules.*.status' => 'nullable|string',

            // Peak Rules
            'peak_rules' => 'nullable|array',
            'peak_rules.*.day_of_week' => 'required|string',
            'peak_rules.*.start_time' => 'required|string',
            'peak_rules.*.end_time' => 'required|string',
            'peak_rules.*.pricing_type' => 'required|string|in:multiplier,percentage,fixed',
            'peak_rules.*.value' => 'required|numeric|min:0',
            'peak_rules.*.status' => 'nullable|string',

            // Weekend Rules
            'weekend_rules' => 'nullable|array',
            'weekend_rules.*.days' => 'required|string',
            'weekend_rules.*.pricing_type' => 'required|string|in:multiplier,percentage,fixed',
            'weekend_rules.*.value' => 'required|numeric|min:0',
            'weekend_rules.*.status' => 'nullable|string',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        \Illuminate\Support\Facades\Log::error('PricingPlanRequest validation failed!', [
            'errors' => $validator->errors()->toArray(),
            'input' => $this->all(),
        ]);
        parent::failedValidation($validator);
    }
}
