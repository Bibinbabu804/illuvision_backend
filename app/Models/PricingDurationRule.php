<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingDurationRule extends Model
{
    protected $table = 'pricing_duration_rules';

    protected $fillable = [
        'pricing_plan_id',
        'minimum_duration',
        'maximum_duration',
        'duration_unit',
        'discount_type',
        'discount_value',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'pricing_plan_id' => 'integer',
            'minimum_duration' => 'decimal:2',
            'maximum_duration' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class, 'pricing_plan_id');
    }

    public function splitOverrides(): HasMany
    {
        return $this->hasMany(ScreenDurationSplitOverride::class, 'pricing_duration_rule_id');
    }
}
