<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\PaymentStatus;
use App\Models\Payment;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0.9 — Payment status vocabulary regression.
 *
 * `PaymentStatus` is a read-only alias layer. The model remains the single
 * source of truth for values. This test fails if an alias drifts away from the
 * model constant, which would silently fork the status vocabulary.
 */
class PaymentStatusTest extends TestCase
{
    public function test_legacy_status_aliases_match_model_constants(): void
    {
        $this->assertSame(Payment::STATUS_PENDING, PaymentStatus::PENDING);
        $this->assertSame(Payment::STATUS_VERIFICATION_PENDING, PaymentStatus::VERIFICATION_PENDING);
        $this->assertSame(Payment::STATUS_PAID, PaymentStatus::PAID);
        $this->assertSame(Payment::STATUS_FAILED, PaymentStatus::FAILED);
        $this->assertSame(Payment::STATUS_REFUNDED, PaymentStatus::REFUNDED);
        $this->assertSame(Payment::STATUS_PARTIALLY_REFUNDED, PaymentStatus::PARTIALLY_REFUNDED);
    }

    public function test_payment_mode_aliases_match_model_constants(): void
    {
        $this->assertSame(Payment::PAYMENT_STATUS_PENDING, PaymentStatus::MODE_PENDING);
        $this->assertSame(Payment::PAYMENT_STATUS_AWAITING_VERIFICATION, PaymentStatus::AWAITING_VERIFICATION);
        $this->assertSame(Payment::PAYMENT_STATUS_COMPLETED, PaymentStatus::COMPLETED);
    }

    public function test_verification_and_refund_column_aliases_match_model_constants(): void
    {
        $this->assertSame(Payment::VERIFICATION_PENDING, PaymentStatus::VERIFICATION_PENDING_COLUMN);
        $this->assertSame(Payment::VERIFICATION_VERIFIED, PaymentStatus::VERIFICATION_VERIFIED);
        $this->assertSame(Payment::VERIFICATION_FAILED, PaymentStatus::VERIFICATION_FAILED);

        $this->assertSame(Payment::REFUND_PENDING, PaymentStatus::REFUND_PENDING);
        $this->assertSame(Payment::REFUND_PROCESSED, PaymentStatus::REFUND_PROCESSED);
        $this->assertSame(Payment::REFUND_FAILED, PaymentStatus::REFUND_FAILED);
    }

    public function test_both_status_vocabularies_are_preserved_verbatim(): void
    {
        // The two vocabularies are only *partially* distinct. `pending` is
        // shared by both, while the terminal states are not. The extraction
        // must preserve this overlap exactly rather than normalise it away.
        $this->assertSame(PaymentStatus::PENDING, PaymentStatus::MODE_PENDING);
        $this->assertNotSame(PaymentStatus::PAID, PaymentStatus::COMPLETED);
        $this->assertSame(PaymentStatus::FAILED, PaymentStatus::VERIFICATION_FAILED);
    }
}
