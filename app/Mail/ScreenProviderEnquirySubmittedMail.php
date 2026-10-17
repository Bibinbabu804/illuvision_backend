<?php

namespace App\Mail;

use App\Models\ScreenProviderEnquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ScreenProviderEnquirySubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public ScreenProviderEnquiry $enquiry;

    public function __construct(ScreenProviderEnquiry $enquiry)
    {
        $this->enquiry = $enquiry;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Screen Provider Enquiry - ' . $this->enquiry->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.screen-provider-enquiry-submitted',
        );
    }
}
