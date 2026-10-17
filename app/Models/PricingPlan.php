<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingPlan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'base_price',
        'currency',
        'base_price_original',
        'base_price_currency',
        'conversion_rate_to_inr',
        'minimum_duration',
        'minimum_duration_type',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'base_price'             => 'decimal:2',
            'base_price_original'    => 'decimal:2',
            'conversion_rate_to_inr' => 'decimal:8',
            'minimum_duration'       => 'integer',
            'is_default'             => 'boolean',
            'is_active'              => 'boolean',
        ];
    }

    public function screenRules(): HasMany
    {
        return $this->hasMany(PricingScreenRule::class, 'pricing_plan_id')->orderBy('sort_order');
    }

    public function durationRules(): HasMany
    {
        return $this->hasMany(PricingDurationRule::class, 'pricing_plan_id')->orderBy('sort_order');
    }

    public function peakRules(): HasMany
    {
        return $this->hasMany(PricingPeakRule::class, 'pricing_plan_id');
    }

    public function weekendRules(): HasMany
    {
        return $this->hasMany(PricingWeekendRule::class, 'pricing_plan_id');
    }

    public function durationSplitOverrides(): HasMany
    {
        return $this->hasMany(ScreenDurationSplitOverride::class, 'pricing_plan_id');
    }
}
