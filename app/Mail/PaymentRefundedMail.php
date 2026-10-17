<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentRefundedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Payment $payment;
    public ?string $reason;

    public function __construct(Payment $payment, ?string $reason = null)
    {
        $this->payment = $payment;
        $this->reason = $reason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Refund Processed - ₹' . number_format($this->payment->amount, 2),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-refunded',
            with: ['payment' => $this->payment, 'reason' => $this->reason],
        );
    }

    public function getPayment(): Payment
    {
        return $this->payment;
    }
}
