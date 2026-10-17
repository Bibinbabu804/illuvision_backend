<?php

namespace App\Support\Events\Notifications;

use App\Models\Booking;
use App\Models\ReferralCode;
use App\Models\ReferralUsage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReferralUsed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ReferralUsage $usage;
    public ?Booking $booking = null;
    public ?ReferralCode $referralCode = null;

    public function __construct(ReferralUsage $usage)
    {
        $this->usage = $usage;
        $this->booking = $usage->relationLoaded('booking') ? $usage->booking : ($usage->booking_id ? Booking::find($usage->booking_id) : null);
        $this->referralCode = $usage->relationLoaded('referralCode') ? $usage->referralCode : ($usage->referral_code_id ? ReferralCode::find($usage->referral_code_id) : null);
    }

    public function notificationKey(): string
    {
        return 'referral_used';
    }
}
