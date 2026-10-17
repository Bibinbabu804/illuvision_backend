<?php

namespace App\Domain\Financial\Contracts;

use App\Models\BookingScreen;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * SettlementRepositoryInterface
 *
 * Persistence boundary for settlement *eligibility retrieval* (Phase 0.10).
 *
 * JUSTIFICATION (Architecture Rule 6):
 * Settlement eligibility is the most complex aggregate read in the financial
 * subsystem: it joins booking_screens to bookings, filters on ownership,
 * settlement status, a set of four qualifying booking statuses and a
 * three-branch period-overlap predicate. It is also the read that decides how
 * much real money leaves the platform, so it must be isolated behind an
 * auditable contract rather than inlined in an application service.
 *
 * CONTRACT FIDELITY:
 * The implementation MUST return exactly the rows the legacy inline query
 * returned, with identical predicates, ordering and hydration. Settlement
 * amounts are derived exclusively from immutable `booking_screens` snapshots.
 * This interface is READ-ONLY — it must not mutate bookings or booking screens.
 */
interface SettlementRepositoryInterface
{
    /**
     * Booking-screen rows eligible for settlement for one screen provider.
     *
     * Eligibility (unchanged):
     *  - screen_provider_id = $screenProviderId
     *  - owner_type          = screen_provider
     *  - settlement_status   = 'pending'
     *  - booking status IN (approved, scheduled, running, completed)
     *  - when $bookingId is supplied: booking_id = $bookingId (period ignored)
     *  - otherwise: booking period overlaps [$periodStart, $periodEnd] using the
     *    legacy (end BETWEEN) OR (start BETWEEN) OR (start <= from AND end >= to)
     *    predicate.
     *
     * @return Collection<int, BookingScreen>
     */
    public function findEligibleBookingScreens(
        int $screenProviderId,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?int $bookingId = null
    ): Collection;
}
