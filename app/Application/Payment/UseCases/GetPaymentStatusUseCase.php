<?php

namespace App\Application\Payment\UseCases;

use App\Models\Payment;

/**
 * GetPaymentStatusUseCase
 *
 * Application read model for "what is the current payment position of this
 * booking?".
 *
 * WHY A USE CASE:
 * The projection is assembled from three sources — the booking status, the
 * latest payment row, and a fixed projection of payment columns — and it is
 * consumed by a public API endpoint. Owning the projection here keeps the
 * controller free of persistence access and makes the response shape an
 * explicitly tested contract.
 *
 * BEHAVIOURAL CONTRACT (unchanged):
 *  - The "latest" payment is `payments` ordered by `created_at` descending,
 * *    first row — i.e. ties are broken by insertion order exactly as before.
 *  - When no payment exists, `payment` is null (never an empty object).
 *  - The exposed keys and their order are frozen:
 *    id, razorpay_order_id, razorpay_payment_id, amount, currency,
 *    payment_status, paid_at, refund_status
 *  - No gateway call is made. This endpoint never touches Razorpay.
 */
class GetPaymentStatusUseCase
{
    public function __construct(
        protected PaymentStatusProjector $projector
    ) {}

    /**
     * @return array{booking_id: int, booking_status: string, payment: array<string, mixed>|null}
     */
    public function execute(int $bookingId): array
    {
        return $this->projector->project($bookingId);
    }
}
