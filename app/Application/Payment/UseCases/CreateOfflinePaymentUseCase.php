<?php

namespace App\Application\Payment\UseCases;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\ManualBookingService;
use App\Support\Events\Notifications\PaymentReceived;

/**
 * CreateOfflinePaymentUseCase
 *
 * Application entry point for the *canonical* offline/direct payment recording
 * operation ("a human confirmed money arrived outside the gateway").
 *
 * WHY A USE CASE:
 * Offline payment recording is a genuine multi-channel application operation.
 * It is reachable from the admin manual-booking payment endpoint today and from
 * the manual-booking create flow with an offline payment mode. It also owns a
 * financial side-effect chain (payment row, booking transition, referral
 * attribution, booking activity, `PaymentReceived` event) that must not be
 * duplicated per channel.
 *
 * BEHAVIOURAL CONTRACT (unchanged — delegated wholesale):
 * Everything happens inside `ManualBookingService::recordOfflinePayment()`:
 *  - payment row: payment_status = 'paid', paid_at = now, amount as supplied
 *    (NOT re-derived from the booking), currency hard-coded 'INR'.
 *  - booking.payment_mode / offline_payment_reference set only when unset.
 *  - booking payment_pending -> pending_approval only when still pending.
 *  - referral attribution is attempted inside a try/catch: failures are logged
 *    as warnings and do NOT roll back the payment.
 *  - `PaymentReceived` is dispatched INSIDE the transaction.
 *
 * KNOWN DIVERGENCE — deliberately NOT consolidated in this phase:
 * `BookingController::markManualPayment()` (POST /bookings/{booking}/manual-payment)
 * is a second, historically different offline-payment path. It does NOT update
 * `booking.payment_mode`, does NOT perform referral attribution, and produces a
 * different response envelope. Merging it into this use case would change
 * behaviour, so it is left untouched and recorded as deferred technical debt in
 * docs/audit/phase-0.10-refund-map.json → "divergent_offline_payment_paths".
 */
class CreateOfflinePaymentUseCase
{
    public function __construct(
        protected ManualBookingService $manualBookingService
    ) {}

    /**
     * @param  array{payment_mode: string, amount: float|int|string, reference?: string|null, notes?: string|null}  $data
     */
    public function execute(Booking $booking, array $data, User $actor): Payment
    {
        return $this->manualBookingService->recordOfflinePayment($booking, $data, $actor);
    }
}
