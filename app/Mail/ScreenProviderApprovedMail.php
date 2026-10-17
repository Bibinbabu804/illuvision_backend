<?php

namespace App\Mail;

use App\Models\ScreenProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ScreenProviderApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public ScreenProvider $screenProvider;
    public string $setupUrl;

    public function __construct(ScreenProvider $screenProvider, string $setupUrl = '')
    {
        $this->screenProvider = $screenProvider;
        $this->setupUrl = $setupUrl;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Illuvision Network - Your Application is Approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.screen-provider-approved',
            with: [
                'screenProvider' => $this->screenProvider,
                'setupUrl' => $this->setupUrl,
            ],
        );
    }
}
