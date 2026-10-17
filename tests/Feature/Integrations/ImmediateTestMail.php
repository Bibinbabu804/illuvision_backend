<?php

namespace Tests\Feature\Integrations;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A mailable that does NOT implement ShouldQueue, used to assert that
 * NotificationService still routes immediate mailables to send() rather than
 * queue() after the mail port was extracted.
 *
 * The real PasswordResetMail implements ShouldQueue, so it cannot be used to
 * exercise the send() branch.
 */
class ImmediateTestMail extends Mailable
{
    public function __construct(public mixed $subject_model = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Immediate test');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset', with: []);
    }
}
