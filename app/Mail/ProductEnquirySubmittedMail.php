<?php

namespace App\Mail;

use App\Models\ProductEnquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProductEnquirySubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public ProductEnquiry $enquiry;

    public function __construct(ProductEnquiry $enquiry)
    {
        $this->enquiry = $enquiry;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Product Enquiry Received - ' . $this->enquiry->product->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.product-enquiry-submitted',
            with: [
                'enquiry' => $this->enquiry,
            ],
        );
    }
}
