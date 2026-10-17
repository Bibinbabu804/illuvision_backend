<?php

namespace App\Infrastructure\Notifications\Contracts;

use Illuminate\Mail\Mailable;

/**
 * Outbound email port for the notification layer.
 *
 * Extracted so that App\Services\NotificationService depends on this contract
 * rather than the Mail facade, which makes the notification channel swappable and
 * testable without a mail transport.
 *
 * The queue-vs-send decision stays with the caller: NotificationService inspects
 * ShouldQueue and chooses the matching method, exactly as it did when it called
 * the facade directly.
 */
interface MailContractInterface
{
    /**
     * Deliver a mailable immediately, on the calling thread.
     */
    public function send(string $email, ?string $name, Mailable $mailable): void;

    /**
     * Hand a mailable to the queue for deferred delivery.
     */
    public function queue(string $email, ?string $name, Mailable $mailable): void;
}
