<?php

namespace App\Infrastructure\Notifications;
use App\Infrastructure\Notifications\Contracts\MailContractInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Laravel mail transport adapter.
 *
 * This is the only class in the notification path that touches the Mail facade.
 */
class LaravelMailAdapter implements MailContractInterface
{
    /**
     * {@inheritdoc}
     */
    public function send(string $email, ?string $name, Mailable $mailable): void
    {
        Mail::to($email, $name)->send($mailable);
    }

    /**
     * {@inheritdoc}
     */
    public function queue(string $email, ?string $name, Mailable $mailable): void
    {
        Mail::to($email, $name)->queue($mailable);
    }
}
