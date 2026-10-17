<?php

namespace App\Support\Events\Notifications;

use App\Models\Partner;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PartnerApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Partner $partner;
    public string $setupToken;

    public function __construct(Partner $partner, string $setupToken)
    {
        $this->partner = $partner;
        $this->setupToken = $setupToken;
    }

    public function notificationKey(): string
    {
        return 'partner_approved';
    }
}
