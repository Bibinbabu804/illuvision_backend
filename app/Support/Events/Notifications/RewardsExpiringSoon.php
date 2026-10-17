<?php

namespace App\Support\Events\Notifications;

use App\Models\User;
use App\Models\RewardAccount;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RewardsExpiringSoon
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;
    public int $expiringPoints;
    public string $expiryDate;

    public function __construct(User $user, int $expiringPoints, string $expiryDate)
    {
        $this->user = $user;
        $this->expiringPoints = $expiringPoints;
        $this->expiryDate = $expiryDate;
    }

    public function notificationKey(): string
    {
        return 'rewards_expiring_soon';
    }
}
