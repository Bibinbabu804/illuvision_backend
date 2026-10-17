<?php

namespace App\Services;
use App\Domain\Availability\Exceptions\FleetCapacityExceededException;

class ConflictDetectionService
{
    public function __construct(
        protected InventoryService $inventoryService,
        protected BookingCapacityService $bookingCapacityService
    ) {}

    /**
     * Validate daily booking quotas, fixed time conflicts, and mobile fleet capacity for requested items.
     * @throws FleetCapacityExceededException
     */
    public function validateBookingConflicts(array $items, string $startDate, string $endDate, ?int $excludeBookingId = null): void
    {
        $conflicts = $this->bookingCapacityService->validateBookingCapacity($items, $startDate, $endDate, $excludeBookingId);
        if (!empty($conflicts)) {
            throw new FleetCapacityExceededException(implode(' ', $conflicts));
        }
    }
}
