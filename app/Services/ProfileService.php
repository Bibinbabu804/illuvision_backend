<?php

namespace App\Services;
use App\Infrastructure\Storage\Contracts\StorageContractInterface;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use App\Services\Campaign\ClientService;
use App\Services\Campaign\ClientAnalyticsService;

class ProfileService
{
    public function __construct(
        protected ClientService $clientService,
        protected ClientAnalyticsService $clientAnalyticsService,
        protected StorageContractInterface $storage,
    ) {}

    /**
     * Get comprehensive dashboard overview for the authenticated user.
     */
    public function dashboard(User $user): array
    {
        $profile = $this->clientService->getProfile($user->id);
        $analytics = $this->clientAnalyticsService->clientCampaignAnalytics($user->id);

        // Active campaigns preview
        $activeCampaigns = Booking::with([
            'bookingScreens.screen.city',
            'bookingMedia',
            'payments' => fn ($q) => $q->latest()->limit(1),
        ])
            ->where('user_id', $user->id)
            ->whereIn('status', [
                Booking::STATUS_PENDING_APPROVAL,
                Booking::STATUS_MEDIA_REVIEW_PENDING,
                Booking::STATUS_APPROVED,
                Booking::STATUS_SCHEDULED,
                Booking::STATUS_RUNNING,
            ])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(fn ($b) => $this->formatBookingPreview($b));

        // Recent activity
        $recentActivity = $this->clientService->getTimeline($user->id);

        // Verification updates
        $pendingReview = Booking::where('user_id', $user->id)
            ->whereIn('status', [Booking::STATUS_MEDIA_REVIEW_PENDING, Booking::STATUS_PENDING_APPROVAL])
            ->count();

        $rejectedCampaigns = Booking::where('user_id', $user->id)
            ->where('status', Booking::STATUS_REJECTED)
            ->count();

        $runningNow = Booking::where('user_id', $user->id)->runningNow()->count();

        $verificationUpdates = Booking::with(['bookingMedia'])
            ->where('user_id', $user->id)
            ->whereIn('status', [
                Booking::STATUS_MEDIA_REVIEW_PENDING,
                Booking::STATUS_PENDING_APPROVAL,
                Booking::STATUS_REJECTED,
            ])
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($booking) {
                $statusLabel = match ($booking->status) {
                    Booking::STATUS_REJECTED => 'action_required',
                    Booking::STATUS_MEDIA_REVIEW_PENDING => 'media_review',
                    default => 'pending_review',
                };
                $message = match ($booking->status) {
                    Booking::STATUS_REJECTED => $booking->rejection_reason ?? 'Campaign rejected. Please review and resubmit.',
                    Booking::STATUS_MEDIA_REVIEW_PENDING => 'Media files awaiting admin review.',
                    default => 'Campaign is under review by our engineering team.',
                };

                return [
                    'booking_id' => $booking->id,
                    'campaign_title' => $booking->campaign_title ?? $booking->title,
                    'status' => $booking->status,
                    'status_label' => $statusLabel,
                    'message' => $message,
                    'updated_at' => $booking->updated_at?->diffForHumans(),
                ];
            });

        $referralInfo = $this->clientAnalyticsService->clientReferralInfo($user->id);

        return [
            'profile' => $profile,
            'analytics' => $analytics,
            'active_campaigns' => $activeCampaigns,
            'recent_activity' => $recentActivity,
            'verification_updates' => $verificationUpdates,
            'referral' => $referralInfo,
            'counts' => [
                'active' => $analytics['active_campaigns'],
                'pending_review' => $pendingReview,
                'completed' => $analytics['completed_campaigns'],
                'rejected' => $rejectedCampaigns,
                'running_now' => $runningNow,
            ],
        ];
    }

    /**
     * Get active campaigns for the user.
     */
    public function getActiveCampaigns(User $user, array $filters = [])
    {
        $perPage = $filters['per_page'] ?? 20;

        return Booking::with([
            'bookingScreens.screen.city',
            'bookingMedia',
            'payments' => fn ($q) => $q->latest()->limit(1),
        ])
            ->where('user_id', $user->id)
            ->whereIn('status', [
                Booking::STATUS_PENDING_APPROVAL,
                Booking::STATUS_MEDIA_REVIEW_PENDING,
                Booking::STATUS_APPROVED,
                Booking::STATUS_SCHEDULED,
                Booking::STATUS_RUNNING,
                Booking::STATUS_REJECTED,
            ])
            ->orderByRaw("FIELD(status, 'pending_approval', 'media_review_pending', 'approved', 'scheduled', 'running', 'rejected')")
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get campaign history (completed/cancelled).
     */
    public function getCampaignHistory(User $user, array $filters = [])
    {
        $perPage = $filters['per_page'] ?? 20;
        $status = $filters['status'] ?? null;

        $query = Booking::with([
            'bookingScreens.screen.city',
            'bookingMedia',
            'payments' => fn ($q) => $q->latest()->limit(1),
        ])
            ->where('user_id', $user->id)
            ->whereIn('status', [
                Booking::STATUS_COMPLETED,
                Booking::STATUS_CANCELLED,
                Booking::STATUS_EXPIRED,
                Booking::STATUS_REFUNDED,
            ]);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get campaign details for a single booking.
     */
    public function getCampaignDetails(User $user, int $bookingId): ?Booking
    {
        $booking = Booking::with([
            'bookingScreens.screen.city',
            'bookingMedia',
            'payments',
            'pricingPlan',
            'referralCode.partner',
            'rewardTransactions',
            'rewardRedemptions',
            'bookingActivities' => fn ($q) => $q->latest('created_at')->limit(20),
        ])
            ->where('user_id', $user->id)
            ->where('id', $bookingId)
            ->first();

        if (! $booking) {
            return null;
        }

        $latestPayment = $booking->payments->first();
        $booking->payment_status = $latestPayment?->payment_status ?? 'unpaid';
        $booking->paid_amount = (float) $booking->payments->where('payment_status', 'paid')->sum('amount');
        $booking->screens_count = $booking->bookingScreens->count() ?: ($booking->total_screens ?: 1);
        $booking->first_booking_discount_applied = (bool) $booking->first_booking_discount_applied;
        $booking->first_booking_discount_amount = (float) ($booking->first_booking_discount_amount ?: 0);
        $booking->city_names = $booking->bookingScreens
            ->pluck('screen.city.name')
            ->filter()
            ->unique()
            ->values();

        // Enrich with IlluPoints loyalty summary
        $booking->loyalty = $booking->getLoyaltySummary();

        return $booking;
    }

    /**
     * Upload avatar for a user.
     */
    public function uploadAvatar(User $user, UploadedFile $file): string
    {
        validator(['file' => $file], [
            'file' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ])->validate();

        if ($user->avatar) {
            $this->storage->delete($user->avatar);
        }

        $path = $this->storage->store($file, 'avatars/'.$user->id);
        $user->update(['avatar' => $path]);

        return $this->storage->url($path);
    }

    public function getRecentActivity(int $userId, int $limit = 20): array
    {
        $timeline = $this->clientService->getTimeline($userId);

        return $timeline ?? [];
    }

    protected function formatBookingPreview($booking): array
    {
        $latestPayment = $booking->payments->first();

        return [
            'id' => $booking->id,
            'campaign_title' => $booking->campaign_title ?? $booking->title,
            'status' => $booking->status,
            'date_range' => ($booking->start_time && $booking->end_time)
                ? $booking->start_time->format('M d, Y').' - '.$booking->end_time->format('M d, Y')
                : null,
            'screens_count' => $booking->bookingScreens->count() ?: ($booking->total_screens ?: 1),
            'screen_names' => $booking->bookingScreens->pluck('screen.name')->filter()->take(2)->values(),
            'city_names' => $booking->bookingScreens->pluck('screen.city.name')->filter()->unique()->values(),
            'final_amount' => (float) ($booking->final_amount ?: 0),
            'first_booking_discount_applied' => (bool) $booking->first_booking_discount_applied,
            'first_booking_discount_amount' => (float) ($booking->first_booking_discount_amount ?: 0),
            'payment_status' => $latestPayment?->payment_status ?? 'unpaid',
            'ad_frequency' => $booking->ad_frequency,
            'rejection_reason' => $booking->rejection_reason,
            'created_at' => $booking->created_at?->diffForHumans(),
            // Reward data for booking history
            'reward_points_earned' => (int) ($booking->reward_points_earned ?? 0),
            'reward_points_redeemed' => (int) ($booking->reward_points_redeemed ?? 0),
            'reward_discount_amount' => (float) ($booking->reward_discount_amount ?? 0),
        ];
    }
}
