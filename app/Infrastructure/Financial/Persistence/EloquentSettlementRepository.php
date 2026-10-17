<?php

namespace App\Infrastructure\Financial\Persistence;

use App\Domain\Financial\Contracts\SettlementRepositoryInterface;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\Screen;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * EloquentSettlementRepository
 *
 * Infrastructure adapter for settlement eligibility retrieval.
 *
 * The query below is a byte-for-byte reproduction of the legacy inline query in
 * `SettlementService::generateSettlement()`, including the per-branch
 * `whereHas('booking', ...)` structure and the three-branch period overlap
 * predicate. Do not "simplify" it without a dedicated audit phase.
 */
class EloquentSettlementRepository implements SettlementRepositoryInterface
{
    /**
     * Booking statuses that make a booking_screen financially settleable.
     *
     * @var array<int, string>
     */
    public const SETTLEABLE_BOOKING_STATUSES = [
        Booking::STATUS_APPROVED,
        Booking::STATUS_SCHEDULED,
        Booking::STATUS_RUNNING,
        Booking::STATUS_COMPLETED,
    ];

    public function findEligibleBookingScreens(
        int $screenProviderId,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?int $bookingId = null
    ): Collection {
        $bookingScreensQuery = BookingScreen::where('screen_provider_id', $screenProviderId)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->where('settlement_status', 'pending');

        if ($bookingId) {
            $bookingScreensQuery->where('booking_id', $bookingId)
                ->whereHas('booking', function ($q) {
                    $q->whereIn('status', self::SETTLEABLE_BOOKING_STATUSES);
                });
        } else {
            $bookingScreensQuery->whereHas('booking', function ($q) use ($periodStart, $periodEnd) {
                $q->whereIn('status', self::SETTLEABLE_BOOKING_STATUSES)
                    ->where(function ($dateQ) use ($periodStart, $periodEnd) {
                        $dateQ->whereBetween('end_time', [$periodStart, $periodEnd])
                              ->orWhereBetween('start_time', [$periodStart, $periodEnd])
                              ->orWhere(function ($overlap) use ($periodStart, $periodEnd) {
                                  $overlap->where('start_time', '<=', $periodStart)
                                          ->where('end_time', '>=', $periodEnd);
                              });
                    });
            });
        }

        return $bookingScreensQuery->get();
    }
}
