<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RewardsRedeemedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public int $points;
    public Booking $booking;

    public function __construct(User $user, int $points, Booking $booking)
    {
        $this->user = $user;
        $this->points = $points;
        $this->booking = $booking;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: number_format($this->points) . ' Ad Credits Redeemed on Your Booking',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rewards-redeemed',
            with: [
                'user' => $this->user,
                'points' => $this->points,
                'booking' => $this->booking,
            ],
        );
    }
}
