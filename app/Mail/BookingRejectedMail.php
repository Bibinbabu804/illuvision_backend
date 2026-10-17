<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public string $reason;

    public function __construct(Booking $booking, string $reason = '')
    {
        $this->booking = $booking;
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking Update - ' . ($this->booking->campaign_title ?? $this->booking->title),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-rejected',
            with: ['booking' => $this->booking, 'reason' => $this->reason],
        );
    }

    public function getBooking(): Booking
    {
        return $this->booking;
    }
}
