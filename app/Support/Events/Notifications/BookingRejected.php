<?php

namespace App\Support\Events\Notifications;

use App\Models\Booking;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Booking $booking;

    public string $reason;

    public function __construct(Booking $booking, string $reason = '')
    {
        $this->booking = $booking;
        $this->reason = $reason;
    }

    public function notificationKey(): string
    {
        return 'booking_rejected';
    }
}
