<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPricingBreakdown extends Model
{
    protected $table = 'booking_pricing_breakdowns';

    protected $fillable = [
        'booking_id',
        'screen_id',
        'pricing_plan_id',
        'campaign_date',
        'runtime_hours',
        'base_price',
        'peak_charge',
        'weekend_charge',
        'discount_amount',
        'referral_discount',
        'first_booking_discount',
        'subtotal',
        'final_total',
        'pricing_snapshot',
    ];

    protected $casts = [
        'campaign_date' => 'date',
        'runtime_hours' => 'decimal:2',
        'base_price' => 'decimal:2',
        'peak_charge' => 'decimal:2',
        'weekend_charge' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'referral_discount' => 'decimal:2',
        'first_booking_discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'final_total' => 'decimal:2',
        'pricing_snapshot' => 'array',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    public function pricingPlan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class);
    }
}
