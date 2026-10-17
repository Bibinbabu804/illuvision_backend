<?php

namespace App\Mail;

use App\Models\ScreenProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ScreenProviderPortalInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public ScreenProvider $screenProvider;
    public string $setupUrl;

    public function __construct(ScreenProvider $screenProvider, string $setupUrl)
    {
        $this->screenProvider = $screenProvider;
        $this->setupUrl = $setupUrl;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Illuvision Screen Provider Portal',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.screen-provider-portal-invitation',
            with: [
                'screenProvider' => $this->screenProvider,
                'setupUrl' => $this->setupUrl,
            ],
        );
    }
}
