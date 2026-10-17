<?php

namespace App\Support\Events\Notifications;

use App\Models\ScreenProvider;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScreenProviderRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ScreenProvider $screenProvider;
    public ?string $reason;

    public function __construct(ScreenProvider $screenProvider, ?string $reason = null)
    {
        $this->screenProvider = $screenProvider;
        $this->reason = $reason;
    }

    public function notificationKey(): string
    {
        return 'screen_provider_rejected';
    }
}
