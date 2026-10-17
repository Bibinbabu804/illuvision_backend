<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Payment $payment;

    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment Failed - Booking #' . ($this->payment->booking_id ?? ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-failed',
            with: ['payment' => $this->payment],
        );
    }

    public function getPayment(): Payment
    {
        return $this->payment;
    }
}
