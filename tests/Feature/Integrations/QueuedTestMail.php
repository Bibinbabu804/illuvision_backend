<?php

namespace Tests\Feature\Integrations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A mailable that opts into queued delivery, used to assert that
 * NotificationService still routes ShouldQueue mailables to queue() rather than
 * send() after the mail port was extracted.
 *
 * The real PasswordResetMail also implements ShouldQueue, so the send() branch
 * is covered separately by ImmediateTestMail.
 */
class QueuedTestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public mixed $subject_model = null) {}

    public function build(): self
    {
        return $this->subject('Queued test')->html('<p>queued</p>');
    }
}
