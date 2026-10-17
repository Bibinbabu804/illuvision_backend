<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'referral_code_id',
        'booking_id',
        'user_id',
        'payment_id',
        'partner_id',
        'discount_amount',
        'commission_amount',
        'final_booking_amount',
        'campaign_total',
        'provider_revenue',
        'illuvision_revenue',
        'partner_commission_base',
        'partner_commission_percentage',
        'status',
        'used_at',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'final_booking_amount' => 'decimal:2',
        'campaign_total' => 'decimal:2',
        'provider_revenue' => 'decimal:2',
        'illuvision_revenue' => 'decimal:2',
        'partner_commission_base' => 'decimal:2',
        'partner_commission_percentage' => 'decimal:2',
        'used_at' => 'datetime',
    ];

    // ─── Statuses ─────────────────────────────────────────

    const STATUS_PENDING_PAYMENT = 'pending_payment';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';

    // ─── Relationships ─────────────────────────────────────

    public function referralCode()
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function commission()
    {
        return $this->hasOne(PartnerCommission::class);
    }
}
