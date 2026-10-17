<?php

namespace App\Support\Events\Notifications;

use App\Models\Partner;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PartnerRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Partner $partner;
    public ?string $reason;

    public function __construct(Partner $partner, ?string $reason = null)
    {
        $this->partner = $partner;
        $this->reason = $reason;
    }

    public function notificationKey(): string
    {
        return 'partner_rejected';
    }
}
