<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingScreen extends Model
{
    protected $table = 'booking_screens';

    protected $fillable = [
        'booking_id',
        'screen_id',
        'city_id',
        'quantity',
        'unit_price',
        'line_subtotal',
        'subtotal',
        'provider_commission',
        'platform_commission',
        'pricing_snapshot',
        'status',
        'owner_type',
        'screen_provider_id',
        'screen_provider_name',
        'illuvision_percentage',
        'screen_provider_percentage',
        'gross_revenue',
        'platform_share',
        'provider_share',
        'settlement_status',
        'settlement_id',
        'settled_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'provider_commission' => 'decimal:2',
        'platform_commission' => 'decimal:2',
        'pricing_snapshot' => 'array',
        'illuvision_percentage' => 'decimal:2',
        'screen_provider_percentage' => 'decimal:2',
        'gross_revenue' => 'decimal:2',
        'platform_share' => 'decimal:2',
        'provider_share' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'screen_id')->withTrashed();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'screen_id')->withTrashed();
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ScreenProviderSettlement::class, 'settlement_id');
    }
}
