<?php

namespace App\Support\Events\Notifications;

use App\Models\Booking;
use App\Models\BookingMedia;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MediaRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Booking $booking;
    public BookingMedia $media;
    public string $reason;

    public function __construct(Booking $booking, BookingMedia $media, string $reason = '')
    {
        $this->booking = $booking;
        $this->media = $media;
        $this->reason = $reason;
    }

    public function notificationKey(): string
    {
        return 'media_rejected';
    }
}
