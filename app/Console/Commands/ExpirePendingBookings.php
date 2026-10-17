<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingActivity;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpirePendingBookings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:expire-pending {--ttl=30 : Time to live in minutes for payment_pending status}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire abandoned bookings that remain in payment_pending status past the TTL';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $ttl = max(1, (int) $this->option('ttl'));
        $cutoff = Carbon::now()->subMinutes($ttl);

        $this->info("Scanning for payment_pending bookings created on or before {$cutoff->toDateTimeString()} (TTL: {$ttl}m)...");

        $totalScanned = 0;
        $totalExpired = 0;
        $totalErrors = 0;

        // Process in chunkById batches to avoid memory exhaustion on large datasets
        Booking::withoutGlobalScopes()
            ->where('status', Booking::STATUS_PAYMENT_PENDING)
            ->where('created_at', '<=', $cutoff)
            ->chunkById(100, function ($bookings) use (&$totalScanned, &$totalExpired, &$totalErrors, $ttl) {
                foreach ($bookings as $booking) {
                    $totalScanned++;

                    try {
                        $expired = DB::transaction(function () use ($booking, $ttl) {
                            // Row lock within transaction to avoid race condition with payment verification
                            $locked = Booking::withoutGlobalScopes()
                                ->where('id', $booking->id)
                                ->where('status', Booking::STATUS_PAYMENT_PENDING)
                                ->lockForUpdate()
                                ->first();

                            if (!$locked) {
                                return false;
                            }

                            $locked->update([
                                'status' => Booking::STATUS_EXPIRED,
                            ]);

                            BookingActivity::create([
                                'booking_id' => $locked->id,
                                'type' => 'expired',
                                'label' => 'Booking Expired',
                                'description' => "Booking automatically expired due to payment timeout ({$ttl} minutes)",
                                'performed_by' => null,
                            ]);

                            return true;
                        });

                        if ($expired) {
                            $totalExpired++;
                        }
                    } catch (\Throwable $e) {
                        $totalErrors++;
                        Log::error("Failed to expire booking ID {$booking->id}: {$e->getMessage()}", [
                            'booking_id' => $booking->id,
                            'exception' => $e,
                        ]);
                        $this->error("Error expiring booking ID {$booking->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Completed: Scanned {$totalScanned} candidates, successfully expired {$totalExpired}, errors: {$totalErrors}.");
        Log::info("bookings:expire-pending completed: scanned={$totalScanned}, expired={$totalExpired}, errors={$totalErrors}");

        return Command::SUCCESS;
    }
}
