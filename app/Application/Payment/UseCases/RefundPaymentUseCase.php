<?php

namespace App\Application\Payment\UseCases;

use App\Models\Payment;
use App\Models\Refund;
use App\Services\PaymentService;
use App\Services\RefundService;

/**
 * RefundPaymentUseCase
 *
 * Application entry point for the Payment/Financial refund boundary.
 *
 * TWO DISTINCT BUSINESS PATHS EXIST TODAY. They are NOT duplicates and are
 * deliberately NOT merged (see docs/audit/phase-0.10-refund-map.json):
 *
 *  A. RECORDED REFUND — `RefundService::initiateRefund()`
 *     - Routed and live: POST /api/payments/{id}/refund (permission:payments.approve).
 *     - Creates a `refunds` row (status 'processed', razorpay_refund_id,
 *       refund_reference, initiated_by/at, processed_at, metadata.razorpay_response).
 *     - Writes a `payment_logs` row (EVENT_REFUND_PROCESSED / EVENT_REFUND_FAILED).
 *     - Sets `payments.razorpay_refund_id` and `refund_status = 'processed'`.
 *     - On full refund: booking -> 'refunded', which cascades through the
 *       Booking model `updated` hook into loyalty reversal and
 *       `ReferralTrackingService::refundReferralTracking()`.
 *     - `amount` is REQUIRED (>= 0.01) by the endpoint, so the nullable-amount
 *       branch never occurs here.
 *
 *  B. GATEWAY-ONLY REFUND — `PaymentService::refund()`
 *     - Creates NO `refunds` row and NO `payment_logs` row; only mutates the
 *       payment row and the booking.
 *     - Accepts a NULL amount, meaning "refund everything".
 *     - ** UNROUTED / UNREACHABLE ** — no route maps to
 *       `PaymentController::refund()`. It is retained dead code for history and
 *       is exercised only by the boundary regression suite.
 *
 * Both paths remain transaction-wrapped, and both still perform the external
 * gateway call *inside* the transaction (a pre-existing condition recorded in
 * docs/audit/phase-0.9-0.10-financial-concurrency-verification.json as an
 * accepted risk under Architecture Rule 4, deliberately NOT changed here).
 */
class RefundPaymentUseCase
{
    public function __construct(
        protected RefundService $refundService,
        protected PaymentService $paymentService
    ) {}

    /**
     * Path A — recorded refund (the live, routed path).
     */
    public function execute(
        Payment $payment,
        float $amount,
        ?string $reason = null,
        ?int $initiatedBy = null
    ): Refund {
        return $this->refundService->initiateRefund($payment, $amount, $reason, $initiatedBy);
    }

    /**
     * Path B — gateway-only refund (legacy, currently unrouted).
     *
     * @param  float|null  $amount  Null means "refund the full payment amount".
     */
    public function executeLegacyGatewayRefund(Payment $payment, ?float $amount = null): Payment
    {
        return $this->paymentService->refund($payment, $amount);
    }
}
