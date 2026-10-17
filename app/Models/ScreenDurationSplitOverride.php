<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenDurationSplitOverride extends Model
{
    use HasFactory;

    protected $table = 'screen_duration_split_overrides';

    protected $fillable = [
        'screen_id',
        'pricing_plan_id',
        'pricing_duration_rule_id',
        'illuvision_percentage',
        'screen_provider_percentage',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'screen_id' => 'integer',
            'pricing_plan_id' => 'integer',
            'pricing_duration_rule_id' => 'integer',
            'illuvision_percentage' => 'decimal:2',
            'screen_provider_percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'screen_id');
    }

    public function pricingPlan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class, 'pricing_plan_id');
    }

    public function pricingDurationRule(): BelongsTo
    {
        return $this->belongsTo(PricingDurationRule::class, 'pricing_duration_rule_id');
    }
}
