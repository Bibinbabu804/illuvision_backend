<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentLog extends Model
{
    protected $fillable = [
        'payment_id',
        'event',
        'label',
        'description',
        'metadata',
        'performed_by',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    const EVENT_ORDER_CREATED = 'order_created';
    const EVENT_PAYMENT_INITIATED = 'payment_initiated';
    const EVENT_PAYMENT_CAPTURED = 'payment_captured';
    const EVENT_VERIFICATION_COMPLETED = 'verification_completed';
    const EVENT_BOOKING_SUBMITTED = 'booking_submitted';
    const EVENT_BOOKING_APPROVED = 'booking_approved';
    const EVENT_REFUND_INITIATED = 'refund_initiated';
    const EVENT_REFUND_PROCESSED = 'refund_processed';
    const EVENT_REFUND_FAILED = 'refund_failed';
    const EVENT_PAYMENT_FAILED = 'payment_failed';
    const EVENT_VERIFICATION_FAILED = 'verification_failed';

    public static array $eventLabels = [
        self::EVENT_ORDER_CREATED => 'Order Created',
        self::EVENT_PAYMENT_INITIATED => 'Payment Initiated',
        self::EVENT_PAYMENT_CAPTURED => 'Payment Captured',
        self::EVENT_VERIFICATION_COMPLETED => 'Verification Completed',
        self::EVENT_BOOKING_SUBMITTED => 'Booking Submitted',
        self::EVENT_BOOKING_APPROVED => 'Campaign Approved',
        self::EVENT_REFUND_INITIATED => 'Refund Initiated',
        self::EVENT_REFUND_PROCESSED => 'Refund Processed',
        self::EVENT_REFUND_FAILED => 'Refund Failed',
        self::EVENT_PAYMENT_FAILED => 'Payment Failed',
        self::EVENT_VERIFICATION_FAILED => 'Verification Failed',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
