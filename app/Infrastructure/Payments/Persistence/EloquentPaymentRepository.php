<?php

namespace App\Infrastructure\Payments\Persistence;

use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;

/**
 * EloquentPaymentRepository
 *
 * Infrastructure adapter for the Payment persistence boundary.
 *
 * Every method reproduces the exact Eloquent call the legacy inline code used,
 * so that query shape, ordering, hydration and exception type are unchanged.
 */
class EloquentPaymentRepository implements PaymentRepositoryInterface
{
    public function findByGatewayOrderId(string $gatewayOrderId): ?Payment
    {
        return Payment::where('razorpay_order_id', $gatewayOrderId)->first();
    }

    public function findOrFailByGatewayOrderId(string $gatewayOrderId): Payment
    {
        return Payment::where('razorpay_order_id', $gatewayOrderId)->firstOrFail();
    }

    public function findPendingForBooking(int|Booking $booking): ?Payment
    {
        return $this->queryForBooking($booking)
            ->where('payment_status', Payment::STATUS_PENDING)
            ->first();
    }

    public function findLatestForBooking(int|Booking $booking): ?Payment
    {
        return $this->queryForBooking($booking)->latest()->first();
    }

    public function create(array $attributes): Payment
    {
        return Payment::create($attributes);
    }

    public function allForBooking(int|Booking $booking): Collection
    {
        return $this->queryForBooking($booking)->get();
    }

    /**
     * Legacy parity: `Payment::where('booking_id', ...)` with no explicit
     * ordering (MySQL/SQLite fall back to primary-key order for the simple case).
     */
    private function queryForBooking(int|Booking $booking)
    {
        $bookingId = $booking instanceof Booking ? $booking->id : $booking;

        return Payment::where('booking_id', $bookingId);
    }
}
