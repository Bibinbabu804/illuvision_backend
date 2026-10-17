<?php

namespace App\Support\Events\Notifications;

use App\Models\ContactEnquiry;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactEnquiryAssigned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ContactEnquiry $enquiry;

    public User $assignedBy;

    public function __construct(ContactEnquiry $enquiry, User $assignedBy)
    {
        $this->enquiry = $enquiry;
        $this->assignedBy = $assignedBy;
    }

    public function notificationKey(): string
    {
        return 'contact_enquiry_assigned';
    }
}
