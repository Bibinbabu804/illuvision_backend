<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RewardsExpiringSoonMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public int $expiringPoints;
    public string $expiryDate;

    public function __construct(User $user, int $expiringPoints, string $expiryDate)
    {
        $this->user = $user;
        $this->expiringPoints = $expiringPoints;
        $this->expiryDate = $expiryDate;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: number_format($this->expiringPoints) . ' Ad Credits Expiring Soon',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rewards-expiring-soon',
            with: [
                'user' => $this->user,
                'expiringPoints' => $this->expiringPoints,
                'expiryDate' => $this->expiryDate,
            ],
        );
    }
}
