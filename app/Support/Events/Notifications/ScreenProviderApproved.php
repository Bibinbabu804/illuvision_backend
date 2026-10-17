<?php

namespace App\Support\Events\Notifications;

use App\Models\ScreenProvider;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScreenProviderApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ScreenProvider $screenProvider;
    public string $setupToken;

    public function __construct(ScreenProvider $screenProvider, string $setupToken = '')
    {
        $this->screenProvider = $screenProvider;
        $this->setupToken = $setupToken;
    }

    public function notificationKey(): string
    {
        return 'screen_provider_approved';
    }
}
