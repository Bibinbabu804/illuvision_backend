<?php

namespace App\Support\Events\Notifications;

use App\Models\Booking;
use App\Models\BookingMedia;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MediaUploaded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Booking $booking;
    public array $mediaItems;

    public function __construct(Booking $booking, array $mediaItems)
    {
        $this->booking = $booking;
        $this->mediaItems = $mediaItems;
    }

    public function notificationKey(): string
    {
        return 'media_uploaded';
    }
}
