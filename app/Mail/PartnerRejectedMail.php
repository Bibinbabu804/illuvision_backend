<?php

namespace App\Mail;

use App\Models\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Partner $partner;
    public ?string $reason;

    public function __construct(Partner $partner, ?string $reason = null)
    {
        $this->partner = $partner;
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Application Update - ILLUVISSION Partner Program',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.partner-rejected',
            with: [
                'partner' => $this->partner,
                'reason' => $this->reason,
            ],
        );
    }
}
