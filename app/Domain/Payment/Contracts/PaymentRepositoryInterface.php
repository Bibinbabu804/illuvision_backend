<?php

namespace App\Domain\Payment\Contracts;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;

/**
 * PaymentRepositoryInterface
 *
 * Persistence boundary for the Payment domain (Phase 0.9).
 *
 * JUSTIFICATION (Architecture Rule 6 — high-value repository abstraction only):
 * Payment rows are financial records. Isolating their retrieval/creation behind
 * a contract keeps the Payment application layer free of Eloquent statics and
 * makes the (currently implicit) payment lookup semantics — "one pending row
 * per booking", "latest row for booking", "row by gateway order id" — explicit
 * and testable.
 *
 * CONTRACT FIDELITY:
 * Implementations MUST reproduce the exact query semantics of the legacy inline
 * calls they replace. In particular `findPendingForBooking()` orders by primary
 * key ascending (the legacy `->first()` did the same) and
 * `findOrFailByGatewayOrderId()` MUST throw
 * `Illuminate\Database\Eloquent\ModelNotFoundException` exactly as the legacy
 * `firstOrFail()` did, because the HTTP layer maps that exception.
 *
 * No query, ordering, locking or persistence semantic may be changed here.
 */
interface PaymentRepositoryInterface
{
    /**
     * Find the payment row attached to an external gateway order id.
     */
    public function findByGatewayOrderId(string $gatewayOrderId): ?Payment;

    /**
     * Find the payment row attached to an external gateway order id or fail.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFailByGatewayOrderId(string $gatewayOrderId): Payment;

    /**
     * Find the first `pending` payment row for a booking (lowest primary key).
     *
     * This is the payment-level order-reuse guard used when re-issuing a
     * gateway order for a booking that already has one open.
     */
    public function findPendingForBooking(int|Booking $booking): ?Payment;

    /**
     * Find the most recently created payment row for a booking.
     */
    public function findLatestForBooking(int|Booking $booking): ?Payment;

    /**
     * Persist a new payment row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Payment;

    /**
     * All payment rows for a booking, ordered by primary key ascending.
     *
     * @return Collection<int, Payment>
     */
    public function allForBooking(int|Booking $booking): Collection;
}
