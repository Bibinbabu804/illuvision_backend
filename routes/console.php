<?php

use Illuminate\Support\Facades\Schedule;

// ─── Scheduled Tasks ──────────────────────────────────────

// Sync exchange rates from ExchangeRate-API every 12 hours
Schedule::command('currency:sync-rates')->twiceDaily(1, 13);

// Display an inspiring quote (built-in Laravel)
Schedule::command('inspire')->hourly();

// Expire reward points and send expiry warnings daily at midnight
Schedule::command('rewards:expire')->daily();

// Expire abandoned payment_pending bookings past 30m TTL every 5 minutes
Schedule::command('bookings:expire-pending')->everyFiveMinutes();

use App\Mail\PartnerApprovedMail;
use App\Models\Partner;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Artisan;

Artisan::command('mail:test-approved {email}', function ($email) {
    $partner = new Partner([
        'name' => 'Achu Test',
        'email' => $email,
    ]);
    
    $setupUrl = 'http://localhost:3000/partner/setup-password?email=' . urlencode($email) . '&token=test-token';
    
    Mail::to($email)->send(new PartnerApprovedMail($partner, $setupUrl));
    
    $this->info('Test partner approval email sent successfully!');
})->purpose('Send a test partner approval email');

