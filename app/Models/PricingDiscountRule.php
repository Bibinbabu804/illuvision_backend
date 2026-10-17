<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingDiscountRule extends Model
{
    protected $fillable = [
        'pricing_plan_id',
        'applies_after_duration',
        'duration_type',
        'reduction_type',
        'reduction_value',
        'max_discount',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pricing_plan_id' => 'integer',
            'applies_after_duration' => 'integer',
            'reduction_value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class, 'pricing_plan_id');
    }
}
