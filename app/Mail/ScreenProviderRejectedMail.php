<?php

namespace App\Mail;

use App\Models\ScreenProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ScreenProviderRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public ScreenProvider $screenProvider;
    public ?string $reason;

    public function __construct(ScreenProvider $screenProvider, ?string $reason = null)
    {
        $this->screenProvider = $screenProvider;
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Update on Your Illuvision Network Application',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.screen-provider-rejected',
            with: [
                'screenProvider' => $this->screenProvider,
                'reason' => $this->reason,
            ],
        );
    }
}
