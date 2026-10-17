<?php

namespace App\Application\Booking\Queries;

use App\Models\Screen;
use App\Services\BookingCapacityService;
use App\Services\InventoryService;
use Carbon\Carbon;

/**
 * CheckBookingAvailabilityQuery
 *
 * The canonical read model behind `POST /api/bookings/check-conflicts`.
 *
 * WHY A QUERY OBJECT:
 * `BookingController::checkConflicts()` was an 80-line method containing a
 * three-way decision tree over availability rules that are not obvious from the
 * controller's point of view: a mobile-fleet branch, an intraday-vs-multi-day
 * branch that additionally depends on the screen's pricing plan unit
 * (`Minute`/`Hour`), a fixed-screen-conflict check that only applies to intraday
 * windows, and a daily-capacity fallback that runs only if the fixed check
 * passed. It also had to normalise three differently shaped conflict payloads
 * into one response structure.
 *
 * This is read-only orchestration with no transaction and no writes, which makes
 * it a Query rather than a Use Case. Extracting it gives booking creation,
 * rescheduling and conflict checking a single place where the availability rules
 * are stated, instead of each controller path calling `checkEligibility`
 * slightly differently.
 *
 * BEHAVIOURAL CONTRACT — UNCHANGED. Pure relocation:
 *  - Same branch order: fleet first for mobile, otherwise intraday fixed-conflict
 *    then daily-capacity.
 *  - Same `isIntraday` derivation, including the `Minute`/`Hour` plan override.
 *  - The daily-capacity result still *replaces* (rather than appends to) the
 *    conflict list.
 *  - Missing screen still yields `null`, which the controller turns into 404.
 */
class CheckBookingAvailabilityQuery
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected BookingCapacityService $capacityService,
    ) {}

    /**
     * @return array{has_available_capacity: bool, capacity_conflicts: array<int, array<string, mixed>>}|null
     *                                                                                                        Null when the screen does not exist.
     */
    public function execute(int $screenId, string $startTime, string $endTime): ?array
    {
        $screen = Screen::find($screenId);
        if (! $screen) {
            return null;
        }

        $conflicts = [];
        $hasCapacity = true;

        if ($screen->is_mobile) {
            $avail = $this->inventoryService->getAvailableFleet($screen->id, $startTime, $endTime);
            $hasCapacity = $avail > 0;
            if (! $hasCapacity) {
                $conflicts[] = [
                    'screen_id' => $screen->id,
                    'screen_name' => $screen->name,
                    'available_units' => $avail,
                    'fleet_size' => $screen->fleet_size,
                    'reason' => 'Fleet capacity is fully booked for the requested time interval.',
                ];
            }
        } else {
            $plan = $screen->pricingPlan;
            $startCarbon = Carbon::parse($startTime);
            $endCarbon = Carbon::parse($endTime);
            $dailyStartTime = $startCarbon->format('H:i');
            $dailyEndTime = $endCarbon->format('H:i');
            $isMinutePlan = $plan && in_array($plan->minimum_duration_type, ['Minute', 'Hour']);
            $isIntraday = ($dailyStartTime !== '00:00' || ($dailyEndTime !== '23:59' && $dailyEndTime !== '00:00')) || $isMinutePlan;

            if ($isIntraday) {
                $hasFixedConflict = $this->inventoryService->hasFixedScreenConflict($screen->id, $startTime, $endTime);

                if ($hasFixedConflict) {
                    $hasCapacity = false;
                    $conflicts[] = [
                        'screen_id' => $screen->id,
                        'screen_name' => $screen->name,
                        'reason' => 'Fixed screen has a conflicting booking in the selected time window.',
                    ];
                }
            }

            if ($hasCapacity) {
                $startDate = $startCarbon->format('Y-m-d');
                $endDate = $endCarbon->format('Y-m-d');

                $dailyConflicts = $this->capacityService->validateScreenDailyCapacity([$screenId], $startDate, $endDate);

                if (! empty($dailyConflicts)) {
                    $hasCapacity = false;
                    $conflicts = $dailyConflicts;
                }
            }
        }

        return [
            'has_available_capacity' => $hasCapacity,
            'capacity_conflicts' => $conflicts,
        ];
    }
}
