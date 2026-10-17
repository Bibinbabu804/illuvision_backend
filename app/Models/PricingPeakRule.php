<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingPeakRule extends Model
{
    protected $table = 'pricing_peak_rules';

    protected $fillable = [
        'pricing_plan_id',
        'day_of_week',
        'start_time',
        'end_time',
        'pricing_type',
        'value',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'pricing_plan_id' => 'integer',
            'value' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class, 'pricing_plan_id');
    }
}
