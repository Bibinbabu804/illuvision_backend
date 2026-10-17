<?php

namespace App\Services;

use App\Models\Booking;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\InvalidBookingStateException;

class BookingWorkflowService
{
    /**
     * Allowed status transitions graph.
     */
    protected array $allowedTransitions = [
        BookingStatus::DRAFT->value => [
            BookingStatus::PENDING_APPROVAL->value,
            BookingStatus::CANCELLED->value,
        ],
        BookingStatus::PENDING_APPROVAL->value => [
            BookingStatus::APPROVED->value,
            BookingStatus::REJECTED->value,
            BookingStatus::CANCELLED->value,
        ],
        BookingStatus::APPROVED->value => [
            BookingStatus::SCHEDULED->value,
            BookingStatus::CANCELLED->value,
        ],
        BookingStatus::SCHEDULED->value => [
            BookingStatus::RUNNING->value,
            BookingStatus::CANCELLED->value,
        ],
        BookingStatus::RUNNING->value => [
            BookingStatus::COMPLETED->value,
            BookingStatus::CANCELLED->value,
        ],
        BookingStatus::REJECTED->value => [],
        BookingStatus::COMPLETED->value => [],
        BookingStatus::CANCELLED->value => [],
    ];

    public function transition(Booking $booking, string $targetStatus, ?string $reason = null): Booking
    {
        $currentStatus = $booking->status instanceof BookingStatus ? $booking->status->value : (string) $booking->status;

        $allowed = $this->allowedTransitions[$currentStatus] ?? [];

        if (!in_array($targetStatus, $allowed)) {
            throw new InvalidBookingStateException(
                "Cannot transition booking #{$booking->id} from '{$currentStatus}' to '{$targetStatus}'."
            );
        }

        $updateData = ['status' => $targetStatus];
        if ($reason && $targetStatus === BookingStatus::REJECTED->value) {
            $updateData['sold_out_reason'] = $reason;
        }

        $booking->update($updateData);

        return $booking->fresh();
    }
}
