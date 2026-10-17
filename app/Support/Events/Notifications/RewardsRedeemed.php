<?php

namespace App\Support\Events\Notifications;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RewardsRedeemed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;
    public int $points;
    public Booking $booking;

    public function __construct(User $user, int $points, Booking $booking)
    {
        $this->user = $user;
        $this->points = $points;
        $this->booking = $booking;
    }

    public function notificationKey(): string
    {
        return 'rewards_redeemed';
    }
}
