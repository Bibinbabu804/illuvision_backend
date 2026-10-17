<?php

namespace App\Application\Booking\UseCases;

use App\Application\Booking\Exceptions\BookingCreationException;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingScreen;
use App\Models\Screen;
use App\Models\User;
use App\Services\BookingCapacityService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

/**
 * RescheduleBookingUseCase
 *
 * The canonical application operation behind `PUT /api/bookings/{booking}`.
 *
 * WHY A USE CASE:
 * `BookingController::update()` had grown from a six-line `$booking->update()`
 * into a 134-line transactional reschedule: screen-set diffing, a deterministic
 * merged lock set spanning both the old and the new screens, date-window
 * normalisation, change detection, a self-excluding eligibility check, pivot
 * reconciliation, and status-change activity logging. That is inventory
 * orchestration, not HTTP handling.
 *
 * BEHAVIOURAL CONTRACT — UNCHANGED. This is a relocation, not a redesign:
 *  - Same `DB::transaction` boundary and same lock ordering (merged existing +
 *    target screen ids, deduplicated, then locked).
 *  - The eligibility check still self-excludes the booking being rescheduled, so
 *    it does not conflict with its own existing rows.
 *  - The capacity check is still skipped unless a capacity-consuming parameter
 *    actually changed AND the target status is capacity-consuming.
 *  - The `booking_screens` reconciliation still deletes removed screens first,
 *    then upserts the target set.
 *  - A failed eligibility check still returns HTTP 422 with the same message and
 *    payload (see BookingCreationException for why throwing is observably
 *    identical to the original early return).
 *
 * KNOWN GAP — DELIBERATELY PRESERVED, NOT FIXED HERE:
 * This path can change `end_time`, `quantities` and `screen_id` without
 * recomputing pricing or re-running revenue distribution, so the financial
 * snapshot on the booking can go stale after a reschedule. That is existing,
 * externally observable behaviour. Correcting it would change financial truth,
 * which is out of scope for a refactoring phase. It is recorded as high-severity
 * debt in docs/audit/phase-0.14-architecture-debt-register.json.
 */
class RescheduleBookingUseCase
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected BookingCapacityService $capacityService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Already-validated request payload.
     *
     * @throws BookingCreationException
     */
    public function execute(Booking $booking, array $validated, ?User $actor): Booking
    {
        return DB::transaction(function () use ($validated, $booking, $actor) {
            // Determine existing and target screen IDs
            $existingScreenIds = $booking->bookingScreens()->pluck('screen_id')->all();
            if (empty($existingScreenIds) && $booking->screen_id) {
                $existingScreenIds = [(int) $booking->screen_id];
            }

            if (isset($validated['screen_ids'])) {
                $targetScreenIds = array_values(array_unique(array_map('intval', $validated['screen_ids'])));
            } elseif (isset($validated['screen_id'])) {
                $targetScreenIds = [(int) $validated['screen_id']];
            } else {
                $targetScreenIds = $existingScreenIds;
            }

            // Lock all involved screens (both old and new) deterministically
            $lockScreenIds = array_values(array_unique(array_merge($existingScreenIds, $targetScreenIds)));
            if (! empty($lockScreenIds)) {
                $this->inventoryService->lockScreensForBooking($lockScreenIds);
            }

            $targetStartTime = isset($validated['start_time'])
                ? $this->inventoryService->normalizeDateTime($validated['start_time'], false)
                : $booking->start_time?->format('Y-m-d H:i:s');

            $targetEndTime = isset($validated['end_time'])
                ? $this->inventoryService->normalizeDateTime($validated['end_time'], true)
                : $booking->end_time?->format('Y-m-d H:i:s');

            $targetStatus = $validated['status'] ?? $booking->status;

            // Check if any inventory/schedule-affecting parameters changed
            $screensChanged = ! empty(array_diff($targetScreenIds, $existingScreenIds)) || ! empty(array_diff($existingScreenIds, $targetScreenIds));
            $datesChanged = isset($validated['start_time']) || isset($validated['end_time']);
            $statusChanged = isset($validated['status']) && $validated['status'] !== $booking->status;
            $quantitiesChanged = isset($validated['quantities']);

            $needsCapacityCheck = ($screensChanged || $datesChanged || $statusChanged || $quantitiesChanged)
                && in_array($targetStatus, BookingCapacityService::CAPACITY_CONSUMING_STATUSES);

            if ($needsCapacityCheck && ! empty($targetScreenIds) && $targetStartTime && $targetEndTime) {
                $items = [];
                foreach ($targetScreenIds as $sid) {
                    $qty = $validated['quantities'][$sid] ?? null;
                    if ($qty === null) {
                        $existingBs = $booking->bookingScreens->firstWhere('screen_id', $sid);
                        $qty = $existingBs ? $existingBs->quantity : 1;
                    }
                    $items[] = [
                        'screen_id' => $sid,
                        'quantity' => max(1, (int) $qty),
                    ];
                }

                // Authoritative check with self-exclusion ($booking->id)
                $eligibility = $this->capacityService->checkEligibility(
                    $items,
                    $targetStartTime,
                    $targetEndTime,
                    $booking->id
                );

                if (! $eligibility->isEligible()) {
                    throw new BookingCreationException(
                        $eligibility->getFirstErrorMessage(),
                        422,
                        $eligibility->toArray()
                    );
                }
            }

            $oldStatus = $booking->status;
            $booking->update($validated);

            // Synchronize booking_screens if screens were modified
            if ($screensChanged || isset($validated['quantities'])) {
                // Remove booking_screens for screens no longer in target
                BookingScreen::where('booking_id', $booking->id)
                    ->whereNotIn('screen_id', $targetScreenIds)
                    ->delete();

                // Add or update booking_screens for target screens
                foreach ($targetScreenIds as $sid) {
                    $screenModel = Screen::find($sid);
                    $qty = max(1, (int) ($validated['quantities'][$sid] ?? 1));

                    BookingScreen::updateOrCreate(
                        [
                            'booking_id' => $booking->id,
                            'screen_id' => $sid,
                        ],
                        [
                            'city_id' => $screenModel?->city_id,
                            'quantity' => $qty,
                            'status' => $booking->status,
                        ]
                    );
                }
            }

            // Log status changes
            if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
                BookingActivity::create([
                    'booking_id' => $booking->id,
                    'type' => 'status_changed',
                    'label' => 'Status Changed',
                    'description' => "Status changed from {$oldStatus} to {$validated['status']}",
                    'performed_by' => $actor?->id,
                ]);
            }

            return $booking->fresh(['bookingScreens.screen']);
        });
    }
}
