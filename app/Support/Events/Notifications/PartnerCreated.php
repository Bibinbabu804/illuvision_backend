<?php

namespace App\Support\Events\Notifications;

use App\Models\Partner;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PartnerCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Partner $partner;

    public function __construct(Partner $partner)
    {
        $this->partner = $partner;
    }

    public function notificationKey(): string
    {
        return 'partner_created';
    }
}
