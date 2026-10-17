<?php

namespace App\Support\Events\Notifications;

use App\Models\ScreenProviderEnquiry;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScreenProviderLeadClosed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ScreenProviderEnquiry $enquiry;

    public function __construct(ScreenProviderEnquiry $enquiry)
    {
        $this->enquiry = $enquiry;
    }

    public function notificationKey(): string
    {
        return 'screen_provider_lead_closed';
    }
}
