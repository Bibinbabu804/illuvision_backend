<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingScreenRule extends Model
{
    protected $table = 'pricing_screen_rules';

    protected $fillable = [
        'pricing_plan_id',
        'minimum_screens',
        'maximum_screens',
        'discount_type',
        'discount_value',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'pricing_plan_id' => 'integer',
            'minimum_screens' => 'integer',
            'maximum_screens' => 'integer',
            'discount_value' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class, 'pricing_plan_id');
    }
}
