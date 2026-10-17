<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RewardsBonusAwardedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public int $points;
    public string $reason;

    public function __construct(User $user, int $points, string $reason)
    {
        $this->user = $user;
        $this->points = $points;
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You Received ' . number_format($this->points) . ' Bonus Ad Credits!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rewards-bonus-awarded',
            with: [
                'user' => $this->user,
                'points' => $this->points,
                'reason' => $this->reason,
            ],
        );
    }
}
