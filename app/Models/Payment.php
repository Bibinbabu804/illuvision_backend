<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'booking_id',
        'razorpay_order_id',
        'razorpay_payment_id',
        'razorpay_signature',
        'amount',
        'currency',
        'payment_status',
        'verification_status',
        'payment_method',
        'payment_reference',
        'payment_proof',
        'verified_by_admin_id',
        'verified_at',
        'paid_at',
        'captured_at',
        'error_message',
        'refund_status',
        'razorpay_refund_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'captured_at' => 'datetime',
        'verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ─── Payment Mode Constants ────────────────────────
    const MODE_RAZORPAY = 'razorpay';
    const MODE_DIRECT_BANK = 'direct_bank';
    const MODE_DIRECT_UPI = 'direct_upi';
    const MODE_DIRECT_CASH = 'direct_cash';
    const MODE_CHEQUE = 'cheque';
    const MODE_OTHER = 'other';

    // ─── Payment Status Constants ──────────────────────
    const PAYMENT_STATUS_PENDING = 'pending';
    const PAYMENT_STATUS_AWAITING_VERIFICATION = 'awaiting_verification';
    const PAYMENT_STATUS_COMPLETED = 'completed';
    const PAYMENT_STATUS_FAILED = 'failed';
    const PAYMENT_STATUS_REFUNDED = 'refunded';

    // ─── Status Constants (Legacy Compatibility) ───────
    const STATUS_PENDING = 'pending';
    const STATUS_VERIFICATION_PENDING = 'verification_pending';
    const STATUS_PAID = 'paid';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    const VERIFICATION_PENDING = 'pending';
    const VERIFICATION_VERIFIED = 'verified';
    const VERIFICATION_FAILED = 'failed';

    const REFUND_NONE = null;
    const REFUND_PENDING = 'pending';
    const REFUND_PROCESSED = 'processed';
    const REFUND_FAILED = 'failed';

    // ─── Relationships ───────────────────────────────────

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function verifiedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_admin_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PaymentLog::class)->latest('created_at');
    }

    // ─── Scopes ────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('payment_status', self::STATUS_PENDING);
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', self::STATUS_PAID);
    }

    public function scopeFailed($query)
    {
        return $query->where('payment_status', self::STATUS_FAILED);
    }

    public function scopeRefunded($query)
    {
        return $query->where('payment_status', self::STATUS_REFUNDED);
    }

    public function scopeVerificationPending($query)
    {
        return $query->where('payment_status', self::STATUS_VERIFICATION_PENDING);
    }

    public function scopeByPaymentMethod($query, string $method)
    {
        return $query->where('payment_method', $method);
    }

    public function scopeByDateRange($query, $dateFrom, $dateTo)
    {
        return $query->whereBetween('created_at', [$dateFrom, $dateTo]);
    }

    public function scopeSuccessful($query)
    {
        return $query->whereIn('payment_status', [
            self::STATUS_PAID,
            self::STATUS_REFUNDED,
            self::STATUS_PARTIALLY_REFUNDED,
        ]);
    }

    public function scopeNeedsAttention($query)
    {
        return $query->whereIn('payment_status', [
            self::STATUS_FAILED,
            self::STATUS_VERIFICATION_PENDING,
        ]);
    }
}
