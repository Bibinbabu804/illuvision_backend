<?php

namespace App\Support\Events\Notifications;

use App\Models\ContactEnquiry;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactEnquiryClosed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ContactEnquiry $enquiry;

    public function __construct(ContactEnquiry $enquiry)
    {
        $this->enquiry = $enquiry;
    }

    public function notificationKey(): string
    {
        return 'contact_enquiry_closed';
    }
}
