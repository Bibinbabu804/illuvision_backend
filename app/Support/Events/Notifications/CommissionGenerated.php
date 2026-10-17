<?php

namespace App\Support\Events\Notifications;

use App\Models\PartnerCommission;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommissionGenerated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PartnerCommission $commission;

    public function __construct(PartnerCommission $commission)
    {
        $this->commission = $commission;
    }

    public function notificationKey(): string
    {
        return 'commission_generated';
    }
}
