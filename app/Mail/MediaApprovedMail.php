<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\BookingMedia;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MediaApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public BookingMedia $media;

    public function __construct(Booking $booking, BookingMedia $media)
    {
        $this->booking = $booking;
        $this->media = $media;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Media Approved - ' . ($this->media->original_name ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.media-approved',
            with: ['booking' => $this->booking, 'media' => $this->media],
        );
    }
}
