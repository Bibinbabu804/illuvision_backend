<?php

namespace App\Support\Events\Notifications;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RewardsBonusAwarded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;
    public int $points;
    public string $reason;

    public function __construct(User $user, int $points, string $reason)
    {
        $this->user = $user;
        $this->points = $points;
        $this->reason = $reason;
    }

    public function notificationKey(): string
    {
        return 'rewards_bonus_awarded';
    }
}
