<?php

namespace App\Services;
use App\Support\Events\Notifications\PartnerApproved;
use App\Support\Events\Notifications\PartnerRejected;
use App\Models\Partner;
use App\Models\ReferralCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PartnerService
{
    /**
     * Approve a partner application.
     * Creates user account, assigns partner role, links user to partner, generates referral code.
     */
    public function approve(Partner $partner, User $reviewer): array
    {
        return DB::transaction(function () use ($partner, $reviewer) {
            // Create a user account
            $password = Str::random(16);
            $user = User::create([
                'name' => $partner->name,
                'email' => $partner->email,
                'password' => Hash::make($password),
                'role' => 'partner',
                'phone' => $partner->phone,
            ]);

            // Link user to partner
            $partner->update([
                'user_id' => $user->id,
                'status' => Partner::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'approved_at' => now(),
                'rejected_at' => null,
            ]);

            // Generate a default referral code
            $code = $this->generateReferralCode($partner);
            ReferralCode::create([
                'partner_id' => $partner->id,
                'code' => $code,
                'status' => 'active',
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'commission_type' => 'percentage',
                'commission_value' => 5,
                'usage_limit' => 0,
                'used_count' => 0,
                'start_date' => now(),
                'expiry_date' => now()->addYear(),
            ]);

            // Generate password setup token
            $setupToken = $this->generatePasswordSetupToken($partner->email);

            // Dispatch event
            PartnerApproved::dispatch($partner, $setupToken);

            return [
                'user' => $user,
                'partner' => $partner->fresh(),
                'referral_code' => $code,
                'setup_token' => $setupToken,
            ];
        });
    }

    /**
     * Reject a partner application.
     */
    public function reject(Partner $partner, User $reviewer, ?string $reason = null): Partner
    {
        $partner->update([
            'status' => Partner::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'rejected_at' => now(),
            'approved_at' => null,
            'notes' => $reason ? ($partner->notes . "\n---\nRejection reason: " . $reason) : $partner->notes,
        ]);

        PartnerRejected::dispatch($partner->fresh(), $reason);

        return $partner->fresh();
    }

    /**
     * Generate a unique referral code for a partner.
     */
    public function generateReferralCode(Partner $partner): string
    {
        $prefix = 'ILV';
        $namePart = strtoupper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $partner->name), 0, 4));
        $random = strtoupper(Str::random(4));
        $code = $prefix . $namePart . $random;

        // Ensure uniqueness
        while (ReferralCode::where('code', $code)->exists()) {
            $random = strtoupper(Str::random(4));
            $code = $prefix . $namePart . $random;
        }

        return $code;
    }

    /**
     * Generate a password setup token.
     */
    public function generatePasswordSetupToken(string $email): string
    {
        $token = Str::random(64);
        $expiresAt = now()->addDays(7);

        DB::table('password_setup_tokens')->insert([
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt,
            'used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    /**
     * Set up password using a valid token.
     */
    public function setupPassword(string $email, string $token, string $password): bool
    {
        $record = DB::table('password_setup_tokens')
            ->where('email', $email)
            ->where('token', $token)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return false;
        }

        DB::transaction(function () use ($record, $email, $password) {
            // Update user password
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->update(['password' => Hash::make($password)]);
            }

            // Mark token as used
            DB::table('password_setup_tokens')
                ->where('id', $record->id)
                ->update([
                    'used' => true,
                    'used_at' => now(),
                ]);
        });

        return true;
    }

    /**
     * Get partner dashboard summary with enhanced KPI metrics.
     */
    public function getDashboardSummary(Partner $partner): array
    {
        $referralCodes = $partner->referralCodes;
        $totalReferrals = $referralCodes->sum('used_count');
        $activeReferralCodes = $referralCodes->where('status', 'active')->count();

        // Partner Client metrics
        $totalClients = \App\Models\User::where('created_by_partner_id', $partner->id)->count();

        // Partner Bookings
        $partnerBookings = \App\Models\Booking::where('created_by_partner_id', $partner->id)->get();
        $activeCampaigns = $partnerBookings->whereIn('status', [\App\Models\Booking::STATUS_RUNNING, \App\Models\Booking::STATUS_SCHEDULED])->count();
        $totalRevenue = (float) $partnerBookings->where('status', '!=', \App\Models\Booking::STATUS_CANCELLED)->sum('final_amount');
        $pendingApprovals = $partnerBookings->whereIn('status', [\App\Models\Booking::STATUS_PENDING_APPROVAL, \App\Models\Booking::STATUS_MEDIA_REVIEW_PENDING])->count();

        // Partner Payments
        $partnerBookingIds = $partnerBookings->pluck('id');
        $pendingPayments = \App\Models\Payment::whereIn('booking_id', $partnerBookingIds)
            ->whereIn('payment_status', [\App\Models\Payment::PAYMENT_STATUS_PENDING, \App\Models\Payment::PAYMENT_STATUS_AWAITING_VERIFICATION])
            ->count();

        $commissions = $partner->commissions;

        return [
            'total_clients' => $totalClients,
            'active_campaigns' => $activeCampaigns,
            'total_revenue' => $totalRevenue,
            'pending_payments' => $pendingPayments,
            'pending_approvals' => $pendingApprovals,
            'total_referrals' => $totalReferrals,
            'total_bookings' => $partnerBookings->count(),
            'pending_commissions' => [
                'count' => $commissions->where('status', 'pending')->count(),
                'amount' => (float) $commissions->where('status', 'pending')->sum('amount'),
            ],
            'approved_commissions' => [
                'count' => $commissions->where('status', 'approved')->count(),
                'amount' => (float) $commissions->where('status', 'approved')->sum('amount'),
            ],
            'paid_commissions' => [
                'count' => $commissions->where('status', 'paid')->count(),
                'amount' => (float) $commissions->where('status', 'paid')->sum('amount'),
            ],
            'settlements' => [
                'pending_payout' => (float) $commissions->where('status', 'approved')->sum('amount'),
                'total_settled' => (float) $commissions->where('status', 'paid')->sum('amount'),
            ],
            'lifetime_earnings' => (float) $commissions->sum('amount'),
            'active_referral_codes' => $activeReferralCodes,
            'referral_code' => $referralCodes->where('status', 'active')->first()?->code ?? 'N/A',
        ];
    }

    /**
     * Get partner's referral bookings (from referral usages).
     */
    public function getReferralBookings(Partner $partner, int $perPage = 15): array
    {
        $usages = \App\Models\ReferralUsage::where('partner_id', $partner->id)
            ->with(['booking', 'referralCode'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $bookings = $usages->map(function ($usage) {
            $booking = $usage->booking;
            return [
                'booking_id' => $booking?->id ?? 'N/A',
                'client_name' => $booking?->user?->name ?? 'N/A',
                'campaign' => $booking?->title ?? 'N/A',
                'booking_date' => $booking?->created_at?->format('Y-m-d H:i:s') ?? 'N/A',
                'amount' => (float) ($booking?->total_price ?? 0),
                'status' => $usage->status,
                'referral_discount' => (float) $usage->discount_amount,
                'commission_generated' => (float) $usage->commission_amount,
            ];
        });

        return [
            'data' => $bookings,
            'meta' => [
                'total' => $usages->total(),
                'current_page' => $usages->currentPage(),
                'last_page' => $usages->lastPage(),
                'per_page' => $usages->perPage(),
            ],
        ];
    }

    /**
     * Get partner's commissions.
     */
    public function getCommissions(Partner $partner, int $perPage = 15, ?string $status = null): array
    {
        $commissions = $partner->commissions()
            ->with(['booking'])
            ->when($status, fn($q) => $q->where('status', $status))
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return [
            'data' => $commissions->map(function ($c) {
                return [
                    'id' => $c->id,
                    'booking_id' => $c->booking_id,
                    'amount' => (float) $c->amount,
                    'commission_type' => $c->commission_type,
                    'status' => $c->status,
                    'generated_date' => $c->generated_at?->format('Y-m-d H:i:s') ?? $c->created_at->format('Y-m-d H:i:s'),
                ];
            }),
            'meta' => [
                'total' => $commissions->total(),
                'current_page' => $commissions->currentPage(),
                'last_page' => $commissions->lastPage(),
                'per_page' => $commissions->perPage(),
            ],
        ];
    }

    /**
     * Get partner activity timeline.
     */
    public function getActivityTimeline(Partner $partner, int $limit = 20): array
    {
        $activities = collect();

        // Recent commissions
        $commissions = $partner->commissions()
            ->latest()
            ->take($limit)
            ->get();

        foreach ($commissions as $commission) {
            $activities->push([
                'type' => 'commission_' . $commission->status,
                'description' => 'Commission ' . $commission->status . ' - ₹' . number_format($commission->amount, 2),
                'date' => $commission->updated_at?->format('Y-m-d H:i:s') ?? $commission->created_at->format('Y-m-d H:i:s'),
            ]);
        }

        // Referral codes usage
        $referralCodes = $partner->referralCodes;
        foreach ($referralCodes as $code) {
            $usages = $code->usages()->latest()->take(10)->get();
            foreach ($usages as $usage) {
                $activities->push([
                    'type' => 'referral_used',
                    'description' => 'Referral code ' . $code->code . ' used by a customer',
                    'date' => $usage->used_at?->format('Y-m-d H:i:s') ?? $usage->created_at->format('Y-m-d H:i:s'),
                ]);
            }
        }

        return $activities->sortByDesc('date')->values()->take($limit)->all();
    }
}
