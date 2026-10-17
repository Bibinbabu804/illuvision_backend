<?php

namespace App\Support\Events\Notifications;

use App\Models\ScreenProviderEnquiry;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScreenProviderLeadAssigned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ScreenProviderEnquiry $enquiry;
    public User $assignedBy;

    public function __construct(ScreenProviderEnquiry $enquiry, User $assignedBy)
    {
        $this->enquiry = $enquiry;
        $this->assignedBy = $assignedBy;
    }

    public function notificationKey(): string
    {
        return 'screen_provider_lead_assigned';
    }
}
