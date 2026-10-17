<?php

namespace App\Services\Campaign;

use App\Models\Booking;
use App\Models\User;
use App\Models\RewardAccount;
use App\Models\RewardTransaction;
use App\Models\RewardRedemption;
use App\Support\Events\Notifications\RewardsEarned;
use App\Support\Events\Notifications\RewardsRedeemed;
use App\Support\Events\Notifications\RewardsBonusAwarded;
use Illuminate\Support\Facades\DB;
use App\Services\SettingsService;

class LoyaltyService
{
    protected SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Calculate points that should be earned for a given booking.
     */
    public function calculatePointsToEarn(Booking $booking): int
    {
        return $this->calculatePointsForAmount((float) $booking->final_amount);
    }

    /**
     * Calculate points for a specific amount.
     */
    public function calculatePointsForAmount(float $amount): int
    {
        $enabled = $this->settings->getBool('loyalty', 'enable_rewards', true);
        if (!$enabled) {
            return 0;
        }

        // Calculate points based on settings
        $type = $this->settings->get('loyalty', 'reward_calculation_type', 'percentage');

        if ($type === 'fixed') {
            return $this->settings->getInt('loyalty', 'fixed_points', 50);
        }

        // Spend-based calculation (percentage / ratio)
        $spendRequired = $this->settings->getInt('loyalty', 'spend_required_per_point', 100);
        if ($spendRequired <= 0) {
            return 0;
        }

        return (int) floor($amount / $spendRequired);
    }

    /**
     * Credit earned points to a customer upon booking completion.
     */
    public function creditEarnedPoints(Booking $booking): void
    {
        $enabled = $this->settings->getBool('loyalty', 'enable_rewards', true);
        if (!$enabled) {
            return;
        }

        DB::transaction(function () use ($booking) {
            // Check if points have already been credited for this booking
            $exists = RewardTransaction::where('booking_id', $booking->id)
                ->where('type', RewardTransaction::TYPE_EARN)
                ->exists();

            if ($exists) {
                return;
            }

            $points = $this->calculatePointsToEarn($booking);
            if ($points <= 0) {
                return;
            }

            $account = RewardAccount::firstOrCreate(
                ['user_id' => $booking->user_id],
                ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0, 'lifetime_expired' => 0]
            );

            $expiryDays = $this->settings->getInt('loyalty', 'points_expiry_days', 365);
            $expiryDate = now()->addDays($expiryDays);

            // Create ledger entry
            $transaction = RewardTransaction::create([
                'reward_account_id' => $account->id,
                'booking_id' => $booking->id,
                'type' => RewardTransaction::TYPE_EARN,
                'points' => $points,
                'points_used' => 0,
                'description' => "Earned points on booking #{$booking->id}",
                'expiry_date' => $expiryDate,
            ]);

            // Update account balance
            $account->increment('balance', $points);
            $account->increment('lifetime_earned', $points);

            // Update booking record
            $booking->update([
                'reward_points_earned' => $points,
            ]);

            // Dispatch notification
            event(new RewardsEarned($booking->user, $points, $booking));
        });
    }

    /**
     * Reverse earned points if booking is cancelled or refunded.
     */
    public function reverseEarnedPoints(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            // Find the original earning transaction
            $earnTx = RewardTransaction::where('booking_id', $booking->id)
                ->where('type', RewardTransaction::TYPE_EARN)
                ->first();

            if (!$earnTx) {
                return;
            }

            // Check if already reversed
            $reversed = RewardTransaction::where('booking_id', $booking->id)
                ->where('type', RewardTransaction::TYPE_REFUND_REVERSAL)
                ->where('points', '<', 0)
                ->exists();

            if ($reversed) {
                return;
            }

            $pointsToDeduct = $earnTx->points;

            $account = RewardAccount::where('user_id', $booking->user_id)->first();
            if (!$account) {
                return;
            }

            // Create reversal ledger entry (negative points)
            RewardTransaction::create([
                'reward_account_id' => $account->id,
                'booking_id' => $booking->id,
                'type' => RewardTransaction::TYPE_REFUND_REVERSAL,
                'points' => -$pointsToDeduct,
                'description' => "Reversed earned points for cancelled/refunded booking #{$booking->id}",
            ]);

            // Adjust account balance
            $account->decrement('balance', $pointsToDeduct);
            $account->decrement('lifetime_earned', $pointsToDeduct); // Subtract from lifetime earned

            // Update booking record
            $booking->update([
                'reward_points_earned' => 0,
            ]);
        });
    }

    /**
     * Debit redeemed points from the customer's account (FIFO logic).
     */
    public function debitRedeemedPoints(Booking $booking, int $points): void
    {
        if ($points <= 0) {
            return;
        }

        DB::transaction(function () use ($booking, $points) {
            // Check if already redeemed
            $exists = RewardTransaction::where('booking_id', $booking->id)
                ->where('type', RewardTransaction::TYPE_REDEEM)
                ->exists();

            if ($exists) {
                return;
            }

            $account = RewardAccount::firstOrCreate(
                ['user_id' => $booking->user_id],
                ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0, 'lifetime_expired' => 0]
            );

            // 1. Create main debit transaction in ledger
            $debitTx = RewardTransaction::create([
                'reward_account_id' => $account->id,
                'booking_id' => $booking->id,
                'type' => RewardTransaction::TYPE_REDEEM,
                'points' => -$points,
                'description' => "Redeemed points on booking #{$booking->id}",
            ]);

            // 2. Consume unexpired earned/bonus points in FIFO order
            $activeCredits = RewardTransaction::where('reward_account_id', $account->id)
                ->whereIn('type', [RewardTransaction::TYPE_EARN, RewardTransaction::TYPE_ADMIN_BONUS])
                ->where('points', '>', 0)
                ->whereColumn('points_used', '<', 'points')
                ->where(function ($query) {
                    $query->whereNull('expiry_date')
                          ->orWhere('expiry_date', '>', now());
                })
                ->orderBy('created_at', 'asc')
                ->get();

            $remainingToRedeem = $points;
            $pointValue = (float) $this->settings->get('loyalty', 'redemption_point_value', 1.00);

            foreach ($activeCredits as $creditTx) {
                if ($remainingToRedeem <= 0) {
                    break;
                }

                $availableInTx = $creditTx->points - $creditTx->points_used;
                $take = min($remainingToRedeem, $availableInTx);

                $creditTx->increment('points_used', $take);

                RewardRedemption::create([
                    'booking_id' => $booking->id,
                    'reward_transaction_id' => $creditTx->id,
                    'points_redeemed' => $take,
                    'discount_amount' => $take * $pointValue,
                ]);

                $remainingToRedeem -= $take;
            }

            // Update account balance
            $account->decrement('balance', $points);
            $account->increment('lifetime_redeemed', $points);

            // Update booking details
            $booking->update([
                'reward_points_redeemed' => $points,
                'reward_discount_amount' => $points * $pointValue,
            ]);

            // Dispatch notification
            event(new RewardsRedeemed($booking->user, $points, $booking));
        });
    }

    /**
     * Refund redeemed points back to the client's balance.
     */
    public function refundRedeemedPoints(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            // Only refund if they actually redeemed points
            if ($booking->reward_points_redeemed <= 0) {
                return;
            }

            // Check if already refunded
            $alreadyRefunded = RewardTransaction::where('booking_id', $booking->id)
                ->where('type', RewardTransaction::TYPE_REFUND_REVERSAL)
                ->where('points', '>', 0)
                ->exists();

            if ($alreadyRefunded) {
                return;
            }

            $pointsToRefund = $booking->reward_points_redeemed;

            $account = RewardAccount::where('user_id', $booking->user_id)->first();
            if (!$account) {
                return;
            }

            // Restore FIFO points_used on the original transactions
            $redemptions = RewardRedemption::where('booking_id', $booking->id)->get();
            foreach ($redemptions as $redemption) {
                $creditTx = RewardTransaction::find($redemption->reward_transaction_id);
                if ($creditTx) {
                    $newPointsUsed = max(0, $creditTx->points_used - $redemption->points_redeemed);
                    $creditTx->update(['points_used' => $newPointsUsed]);
                }
                $redemption->delete();
            }

            // Create refund ledger entry
            RewardTransaction::create([
                'reward_account_id' => $account->id,
                'booking_id' => $booking->id,
                'type' => RewardTransaction::TYPE_REFUND_REVERSAL,
                'points' => $pointsToRefund,
                'description' => "Refunded redeemed points for cancelled/deleted booking #{$booking->id}",
            ]);

            // Restore account balance
            $account->increment('balance', $pointsToRefund);
            $account->decrement('lifetime_redeemed', $pointsToRefund);

            // Update booking details
            $booking->update([
                'reward_points_redeemed' => 0,
                'reward_discount_amount' => 0.00,
            ]);
        });
    }

    /**
     * Add manual adjustments (bonus/debits) by administrators.
     */
    public function adjustPoints(User $user, int $points, string $type, string $reason, ?int $adminId = null): void
    {
        if ($points === 0) {
            return;
        }

        DB::transaction(function () use ($user, $points, $type, $reason, $adminId) {
            $account = RewardAccount::firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0, 'lifetime_expired' => 0]
            );

            $expiryDate = null;
            if ($points > 0 && $type === RewardTransaction::TYPE_ADMIN_BONUS) {
                $expiryDays = $this->settings->getInt('loyalty', 'points_expiry_days', 365);
                $expiryDate = now()->addDays($expiryDays);
            }

            RewardTransaction::create([
                'reward_account_id' => $account->id,
                'type' => $type,
                'points' => $points,
                'points_used' => 0,
                'description' => $points > 0 ? "Admin Bonus credit" : "Admin adjustments deduction",
                'reason' => $reason,
                'admin_id' => $adminId,
                'expiry_date' => $expiryDate,
            ]);

            $account->increment('balance', $points);
            
            if ($points > 0) {
                $account->increment('lifetime_earned', $points);
                // Dispatch notification for bonus
                event(new RewardsBonusAwarded($user, $points, $reason));
            } else {
                $account->increment('lifetime_redeemed', abs($points));
            }
        });
    }
}
