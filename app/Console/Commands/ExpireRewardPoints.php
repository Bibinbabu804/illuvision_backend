<?php

namespace App\Console\Commands;
use App\Support\Events\Notifications\RewardsExpiringSoon;
use App\Models\RewardAccount;
use App\Models\RewardTransaction;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireRewardPoints extends Command
{
    protected $signature = 'rewards:expire';
    protected $description = 'Expire reward points that have passed their expiry date and send expiry warnings';

    public function handle(SettingsService $settings): int
    {
        $enabled = $settings->getBool('loyalty', 'enable_rewards', true);
        if (!$enabled) {
            $this->info('Loyalty rewards system is disabled. Skipping.');
            return 0;
        }

        // --- Phase 1: Expire overdue points ---
        $expiredCount = $this->expireOverduePoints();
        $this->info("Expired {$expiredCount} overdue credit transactions.");

        // --- Phase 2: Send expiry warnings (7 days ahead) ---
        $warningCount = $this->sendExpiryWarnings();
        $this->info("Sent {$warningCount} expiry warning notifications.");

        return 0;
    }

    protected function expireOverduePoints(): int
    {
        // Find all earn/bonus credits that are expired and still have unused points
        $expiredCredits = RewardTransaction::whereIn('type', [
                RewardTransaction::TYPE_EARN,
                RewardTransaction::TYPE_ADMIN_BONUS,
            ])
            ->where('points', '>', 0)
            ->whereColumn('points_used', '<', 'points')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now())
            ->get();

        $count = 0;

        foreach ($expiredCredits as $credit) {
            $unusedPoints = $credit->points - $credit->points_used;

            if ($unusedPoints <= 0) {
                continue;
            }

            DB::transaction(function () use ($credit, $unusedPoints) {
                // Mark all remaining points as used (expired)
                $credit->update(['points_used' => $credit->points]);

                $account = RewardAccount::find($credit->reward_account_id);
                if (!$account) {
                    return;
                }

                // Create expiry ledger entry
                RewardTransaction::create([
                    'reward_account_id' => $account->id,
                    'booking_id' => $credit->booking_id,
                    'type' => RewardTransaction::TYPE_EXPIRE,
                    'points' => -$unusedPoints,
                    'description' => "Points expired from transaction #{$credit->id}",
                ]);

                // Update account balance
                $account->decrement('balance', $unusedPoints);
                $account->increment('lifetime_expired', $unusedPoints);
            });

            $count++;
        }

        return $count;
    }

    protected function sendExpiryWarnings(): int
    {
        // Find credits expiring in the next 7 days that have not yet been warned
        $warningCredits = RewardTransaction::whereIn('type', [
                RewardTransaction::TYPE_EARN,
                RewardTransaction::TYPE_ADMIN_BONUS,
            ])
            ->where('points', '>', 0)
            ->whereColumn('points_used', '<', 'points')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '>', now())
            ->where('expiry_date', '<=', now()->addDays(7))
            ->get();

        // Group by account to send one notification per user
        $grouped = $warningCredits->groupBy('reward_account_id');
        $count = 0;

        foreach ($grouped as $accountId => $credits) {
            $account = RewardAccount::with('user')->find($accountId);
            if (!$account || !$account->user) {
                continue;
            }

            $totalExpiring = $credits->sum(fn ($c) => $c->points - $c->points_used);
            $earliestExpiry = $credits->min('expiry_date');

            if ($totalExpiring <= 0) {
                continue;
            }

            event(new RewardsExpiringSoon(
                $account->user,
                $totalExpiring,
                \Carbon\Carbon::parse($earliestExpiry)->format('M d, Y')
            ));

            $count++;
        }

        return $count;
    }
}
