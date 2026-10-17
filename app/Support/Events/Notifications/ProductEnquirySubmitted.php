<?php

namespace App\Support\Events\Notifications;

use App\Models\ProductEnquiry;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductEnquirySubmitted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ProductEnquiry $enquiry;

    public function __construct(ProductEnquiry $enquiry)
    {
        $this->enquiry = $enquiry;
    }

    public function notificationKey(): string
    {
        return 'product_enquiry_submitted';
    }
}
