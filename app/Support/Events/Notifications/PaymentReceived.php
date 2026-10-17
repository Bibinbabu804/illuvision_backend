<?php

namespace App\Support\Events\Notifications;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Payment $payment;
    public Booking $booking;

    public function __construct(Booking $booking, Payment $payment)
    {
        $this->payment = $payment;
        $this->booking = $booking;
    }

    public function notificationKey(): string
    {
        return 'payment_received';
    }
}
