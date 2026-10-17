<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\ReferralCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReferralUsedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public ReferralCode $referralCode;
    public Booking $booking;

    public function __construct(ReferralCode $referralCode, Booking $booking)
    {
        $this->referralCode = $referralCode;
        $this->booking = $booking;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Referral Code Used - ' . ($this->referralCode->code ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.referral-used',
            with: ['referralCode' => $this->referralCode, 'booking' => $this->booking],
        );
    }
}
