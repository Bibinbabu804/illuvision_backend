<?php

namespace App\Application\Payment\UseCases;

use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Support\Money;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;

/**
 * CreatePaymentOrderUseCase
 *
 * Application use case for creating or retrieving a pending payment order for
 * a booking. (Introduced in Phase 0.5; hardened in Phase 0.9.)
 *
 * BEHAVIOURAL CONTRACT (unchanged since Phase 0.5):
 *  - If a `pending` payment row already exists for the booking, that row is
 *    returned with `is_existing = true` and the payment gateway is NOT called.
 *  - Otherwise `PaymentService::createOrder()` performs the gateway order
 *    creation and the `payments` insert.
 *  - The response `amount` is always integer paise; `currency` is always 'INR'.
 *
 * PHASE 0.9 CHANGE (structural only):
 *  - The pending-order lookup moved from an inline `$booking->payments()`
 *    relation chain to `PaymentRepositoryInterface::findPendingForBooking()`.
 *    The emitted SQL, ordering and hydration are identical.
 *  - The two duplicated `round($x * 100)` expressions now call
 *    `Money::toPaise()`, which reproduces that exact arithmetic.
 */
class CreatePaymentOrderUseCase
{
    public function __construct(
        protected PaymentService $paymentService,
        protected PaymentRepositoryInterface $paymentRepository
    ) {}

    /**
     * Execute the use case.
     *
     * @param Booking $booking
     * @return array{payment: Payment, razorpay_order_id: string, amount: int, currency: string, is_existing: bool}
     */
    public function execute(Booking $booking): array
    {
        // Check for existing pending payment orders
        $existingPending = $this->paymentRepository->findPendingForBooking($booking);

        if ($existingPending) {
            return [
                'payment' => $existingPending->load('booking'),
                'razorpay_order_id' => $existingPending->razorpay_order_id,
                'amount' => Money::toPaise($existingPending->amount),
                'currency' => Money::GATEWAY_CURRENCY,
                'is_existing' => true,
            ];
        }

        $payment = $this->paymentService->createOrder($booking);

        return [
            'payment' => $payment->load('booking'),
            'razorpay_order_id' => $payment->razorpay_order_id,
            'amount' => Money::toPaise($payment->amount),
            'currency' => Money::GATEWAY_CURRENCY,
            'is_existing' => false,
        ];
    }
}
