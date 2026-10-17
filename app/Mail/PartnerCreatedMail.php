<?php

namespace App\Mail;

use App\Models\Partner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Partner $partner;

    public function __construct(Partner $partner)
    {
        $this->partner = $partner;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Partner Registration - ' . ($this->partner->name ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.partner-created',
            with: ['partner' => $this->partner],
        );
    }
}
