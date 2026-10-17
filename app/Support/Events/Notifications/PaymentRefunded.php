<?php

namespace App\Support\Events\Notifications;

use App\Models\Payment;
use App\Models\Booking;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentRefunded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Payment $payment;
    public Booking $booking;
    public ?string $reason;

    public function __construct(Payment $payment, ?string $reason = null)
    {
        $this->payment = $payment;
        $this->booking = $payment->booking;
        $this->reason = $reason;
    }

    public function notificationKey(): string
    {
        return 'payment_refunded';
    }
}
