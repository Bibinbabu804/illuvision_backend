<?php

namespace App\Support\Events\Notifications;

use App\Models\ScreenProvider;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScreenProviderCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ScreenProvider $screenProvider;

    public function __construct(ScreenProvider $screenProvider)
    {
        $this->screenProvider = $screenProvider;
    }

    public function notificationKey(): string
    {
        return 'screen_provider_created';
    }
}
