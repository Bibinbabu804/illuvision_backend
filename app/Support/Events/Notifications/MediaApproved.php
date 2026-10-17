<?php

namespace App\Support\Events\Notifications;

use App\Models\Booking;
use App\Models\BookingMedia;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MediaApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Booking $booking;
    public BookingMedia $media;

    public function __construct(Booking $booking, BookingMedia $media)
    {
        $this->booking = $booking;
        $this->media = $media;
    }

    public function notificationKey(): string
    {
        return 'media_approved';
    }
}
