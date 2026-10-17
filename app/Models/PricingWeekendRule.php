<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingWeekendRule extends Model
{
    protected $table = 'pricing_weekend_rules';

    protected $fillable = [
        'pricing_plan_id',
        'days',
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
