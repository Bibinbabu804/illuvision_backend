<?php

namespace App\Mail;

use App\Models\PartnerCommission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CommissionGeneratedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public PartnerCommission $commission;

    public function __construct(PartnerCommission $commission)
    {
        $this->commission = $commission;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Commission Earned - ₹' . number_format($this->commission->amount, 2),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.commission-generated',
            with: ['commission' => $this->commission],
        );
    }
}
