<?php

namespace App\Application\Payment\UseCases;

use App\Models\Payment;
use App\Services\PaymentService;

/**
 * VerifyPaymentUseCase
 *
 * Application entry point for "a customer says they paid — confirm it".
 *
 * WHY A USE CASE (and not a rename of PaymentService):
 * Payment verification is invoked from three distinct call sites (the public
 * Razorpay verify endpoint, and any future channel that reports a settled
 * gateway payment). It therefore represents a real application operation, not
 * a single-controller action. This class owns *nothing* new: the transaction,
 * the signature check, the payment state mutation, the booking transition and
 * the referral/commission attribution all stay inside
 * `PaymentService::verifyAndConfirmPayment()` untouched, per Architecture
 * Rule 7 (strangler pattern — wrap and delegate, never copy).
 *
 * BEHAVIOURAL CONTRACT (unchanged):
 *  - Success: payment -> 'paid' (+ paid_at, razorpay_payment_id,
 *    razorpay_signature), booking payment_pending -> pending_approval,
 *    referral usage + partner commission created when a referral code exists.
 *  - Invalid signature: payment and booking are updated to their failure values
 *    and then the exception is thrown from inside the same transaction, so the
 *    updates are rolled back and the rows remain 'pending'. This rollback
 *    characteristic is a documented golden behaviour, NOT a bug to be fixed here.
 *  - Duplicate verification: throws 'Payment already verified for this order.'
 *  - Unknown order id: `ModelNotFoundException` from the payment repository.
 */
class VerifyPaymentUseCase
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \Exception
     */
    public function execute(string $razorpayOrderId, string $razorpayPaymentId, string $razorpaySignature): Payment
    {
        return $this->paymentService->verifyAndConfirmPayment(
            $razorpayOrderId,
            $razorpayPaymentId,
            $razorpaySignature
        );
    }
}
