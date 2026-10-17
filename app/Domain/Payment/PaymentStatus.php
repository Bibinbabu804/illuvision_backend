<?php

namespace App\Domain\Payment;

use App\Models\Payment;

/**
 * PaymentStatus
 *
 * The read-only status vocabulary of the Payment boundary.
 *
 * ARCHITECTURAL NOTE (Phase 0.9):
 * `App\Models\Payment` remains the single source of truth for status *values*.
 * Every constant here is an alias of the model constant — it is deliberately
 * NOT an independent enum and it does NOT introduce a new state machine.
 * No transition is enforced here; the existing services remain the transition
 * owners (see docs/audit/phase-0.9-payment-state-transition-map.json).
 *
 * Two distinct vocabularies are in active use and both are preserved verbatim:
 *
 *   1. Legacy compatibility set  — STATUS_PENDING / STATUS_VERIFICATION_PENDING /
 *      STATUS_PAID / STATUS_FAILED / STATUS_REFUNDED / STATUS_PARTIALLY_REFUNDED.
 *      Written by the Razorpay verify/refund flows.
 *   2. Payment-mode set          — PAYMENT_STATUS_PENDING / _AWAITING_VERIFICATION /
 *      _COMPLETED / _FAILED / _REFUNDED.
 *      Written by the direct/offline (admin verification) flows.
 */
final class PaymentStatus
{
    /** Legacy compatibility set (Razorpay verify + refund flows). */
    public const PENDING = Payment::STATUS_PENDING;
    public const VERIFICATION_PENDING = Payment::STATUS_VERIFICATION_PENDING;
    public const PAID = Payment::STATUS_PAID;
    public const FAILED = Payment::STATUS_FAILED;
    public const REFUNDED = Payment::STATUS_REFUNDED;
    public const PARTIALLY_REFUNDED = Payment::STATUS_PARTIALLY_REFUNDED;

    /** Payment-mode set (direct/offline admin verification flows). */
    public const MODE_PENDING = Payment::PAYMENT_STATUS_PENDING;
    public const AWAITING_VERIFICATION = Payment::PAYMENT_STATUS_AWAITING_VERIFICATION;
    public const COMPLETED = Payment::PAYMENT_STATUS_COMPLETED;

    /** Verification column vocabulary. */
    public const VERIFICATION_PENDING_COLUMN = Payment::VERIFICATION_PENDING;
    public const VERIFICATION_VERIFIED = Payment::VERIFICATION_VERIFIED;
    public const VERIFICATION_FAILED = Payment::VERIFICATION_FAILED;

    /** refund_status column vocabulary. */
    public const REFUND_PENDING = Payment::REFUND_PENDING;
    public const REFUND_PROCESSED = Payment::REFUND_PROCESSED;
    public const REFUND_FAILED = Payment::REFUND_FAILED;

    private function __construct()
    {
    }
}
