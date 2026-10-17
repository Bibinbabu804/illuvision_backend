<?php

namespace App\Application\Payment\UseCases;

use App\Models\Booking;
use App\Models\Payment;

/**
 * PaymentStatusProjector
 *
 * Builds the payment-status read model from the booking aggregate.
 *
 * Extracted from `PaymentController::paymentStatus()` verbatim so the HTTP layer
 * no longer performs persistence access. The projection is intentionally a
 * plain array (not an API Resource) because the response envelope is a frozen
 * external contract.
 */
class PaymentStatusProjector
{
    /**
     * Columns exposed by the payment-status endpoint, in their frozen order.
     *
     * @var array<int, string>
     */
    public const PAYMENT_PROJECTION = [
        'id',
        'razorpay_order_id',
        'razorpay_payment_id',
        'amount',
        'currency',
        'payment_status',
        'paid_at',
        'refund_status',
    ];

    /**
     * @return array{booking_id: int, booking_status: string, payment: array<string, mixed>|null}
     */
    public function project(int $bookingId): array
    {
        /** @var Booking $booking */
        $booking = Booking::with('payments')->findOrFail($bookingId);

        $latestPayment = $booking->payments()->latest()->first();

        return [
            'booking_id' => $booking->id,
            'booking_status' => $booking->status,
            'payment' => $latestPayment ? $this->projectPayment($latestPayment) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayment(Payment $payment): array
    {
        $projected = [];

        foreach (self::PAYMENT_PROJECTION as $attribute) {
            $projected[$attribute] = $payment->{$attribute};
        }

        return $projected;
    }
}
