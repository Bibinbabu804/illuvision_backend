<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Domain\Booking\Enums\BookingStatus;
use App\Services\BookingWorkflowService;
use App\Domain\Booking\Exceptions\InvalidBookingStateException;
use Tests\TestCase;

class BookingWorkflowTest extends TestCase
{
    public function test_valid_booking_status_transition_succeeds()
    {
        $booking = Booking::create([
            'booking_reference' => 'ILL-TEST-' . rand(1000, 9999),
            'title' => 'Workflow Unit Test',
            'campaign_title' => 'Workflow Unit Test',
            'advertiser_name' => 'Unit Tester',
            'advertiser_email' => 'test@illuvision.net',
            'advertiser_phone' => '9000000000',
            'start_time' => '2026-10-01 00:00:00',
            'end_time' => '2026-10-05 23:59:59',
            'status' => BookingStatus::DRAFT->value,
            'price' => 5000.00,
        ]);

        $service = new BookingWorkflowService();
        $updated = $service->transition($booking, BookingStatus::PENDING_APPROVAL->value);

        $statusVal = $updated->status instanceof BookingStatus ? $updated->status->value : (string) $updated->status;
        $this->assertEquals(BookingStatus::PENDING_APPROVAL->value, $statusVal);
    }

    public function test_invalid_booking_status_transition_throws_exception()
    {
        $booking = Booking::create([
            'booking_reference' => 'ILL-TEST-' . rand(1000, 9999),
            'title' => 'Workflow Exception Test',
            'campaign_title' => 'Workflow Exception Test',
            'advertiser_name' => 'Unit Tester',
            'advertiser_email' => 'test@illuvision.net',
            'advertiser_phone' => '9000000000',
            'start_time' => '2026-10-01 00:00:00',
            'end_time' => '2026-10-05 23:59:59',
            'status' => BookingStatus::DRAFT->value,
            'price' => 5000.00,
        ]);

        $service = new BookingWorkflowService();

        $this->expectException(InvalidBookingStateException::class);
        $service->transition($booking, BookingStatus::COMPLETED->value);
    }
}
