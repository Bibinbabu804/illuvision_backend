<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartnerCommission extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_id',
        'referral_usage_id',
        'booking_id',
        'payment_id',
        'commission_type',
        'commission_value',
        'campaign_total',
        'provider_revenue',
        'illuvision_revenue',
        'partner_commission_base',
        'partner_commission_percentage',
        'amount',
        'status',
        'paid_at',
        'generated_at',
        'notes',
        'approved_by',
        'approved_at',
        'payout_date',
        'payout_reference',
        'payout_method',
        'payout_notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'commission_value' => 'decimal:2',
        'campaign_total' => 'decimal:2',
        'provider_revenue' => 'decimal:2',
        'illuvision_revenue' => 'decimal:2',
        'partner_commission_base' => 'decimal:2',
        'partner_commission_percentage' => 'decimal:2',
        'paid_at' => 'datetime',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    // ─── Statuses ─────────────────────────────────────────

    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';

    // ─── Relationships ─────────────────────────────────────

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function referralUsage()
    {
        return $this->belongsTo(ReferralUsage::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
