<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RewardAccount;
use App\Models\RewardTransaction;
use App\Services\ProfileService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function __construct(
        protected ProfileService $profileService,
        protected SettingsService $settingsService,
    ) {}

    /**
     * GET /profile/dashboard
     * Get comprehensive dashboard overview for the authenticated user.
     */
    public function dashboard(Request $request)
    {
        $dashboard = $this->profileService->dashboard($request->user());
        return $this->successResponse($dashboard, 'Dashboard fetched successfully');
    }

    /**
     * GET /profile/rewards
     * Get reward dashboard summary for the authenticated customer.
     */
    public function rewards(Request $request)
    {
        $user = $request->user();
        $account = RewardAccount::where('user_id', $user->id)->first();

        if (!$account) {
            return $this->successResponse([
                'balance' => 0,
                'lifetime_earned' => 0,
                'lifetime_redeemed' => 0,
                'lifetime_expired' => 0,
                'available_discount' => 0,
                'min_points_to_redeem' => (int) $this->settingsService->get('loyalty', 'min_points_to_redeem', 100),
                'max_points_per_booking' => (int) $this->settingsService->get('loyalty', 'max_points_redeem_per_booking', 1000),
                'redemption_point_value' => (float) $this->settingsService->get('loyalty', 'redemption_point_value', 1.00),
                'enable_rewards' => $this->settingsService->getBool('loyalty', 'enable_rewards', true),
                'enable_redemption' => $this->settingsService->getBool('loyalty', 'enable_redemption', true),
                'points_expiry_days' => (int) $this->settingsService->get('loyalty', 'points_expiry_days', 365),
                'points_expiring_soon' => 0,
                'next_expiry_date' => null,
                'recent_transactions' => [],
            ], 'Reward summary retrieved');
        }

        // Find points expiring within 30 days
        $expiringSoon = RewardTransaction::where('reward_account_id', $account->id)
            ->where('points', '>', 0)
            ->whereColumn('points_used', '<', 'points')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '>=', now())
            ->where('expiry_date', '<=', now()->addDays(30))
            ->sum(DB::raw('points - points_used'));

        // Next expiry date
        $nextExpiry = RewardTransaction::where('reward_account_id', $account->id)
            ->where('points', '>', 0)
            ->whereColumn('points_used', '<', 'points')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '>', now())
            ->orderBy('expiry_date', 'asc')
            ->first();

        $pointValue = (float) $this->settingsService->get('loyalty', 'redemption_point_value', 1.00);
        $enableRedemption = $this->settingsService->getBool('loyalty', 'enable_redemption', true);

        // Recent 5 transactions
        $recent = RewardTransaction::where('reward_account_id', $account->id)
            ->with(['booking:id,campaign_title,status', 'admin:id,name'])
            ->latest('created_at')
            ->take(5)
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'points' => $tx->points,
                    'running_balance' => null,
                    'description' => $tx->description,
                    'reason' => $tx->reason,
                    'booking' => $tx->booking ? [
                        'id' => $tx->booking->id,
                        'campaign_title' => $tx->booking->campaign_title,
                    ] : null,
                    'created_at' => $tx->created_at?->toIso8601String(),
                ];
            });

        return $this->successResponse([
            'balance' => $account->balance,
            'lifetime_earned' => $account->lifetime_earned,
            'lifetime_redeemed' => $account->lifetime_redeemed,
            'lifetime_expired' => $account->lifetime_expired,
            'available_discount' => $enableRedemption ? round($account->balance * $pointValue, 2) : 0,
            'min_points_to_redeem' => (int) $this->settingsService->get('loyalty', 'min_points_to_redeem', 100),
            'max_points_per_booking' => (int) $this->settingsService->get('loyalty', 'max_points_redeem_per_booking', 1000),
            'redemption_point_value' => $pointValue,
            'enable_rewards' => $this->settingsService->getBool('loyalty', 'enable_rewards', true),
            'enable_redemption' => $enableRedemption,
            'points_expiry_days' => (int) $this->settingsService->get('loyalty', 'points_expiry_days', 365),
            'points_expiring_soon' => (int) $expiringSoon,
            'next_expiry_date' => $nextExpiry?->expiry_date?->toIso8601String(),
            'recent_transactions' => $recent,
        ], 'Reward summary retrieved');
    }

    /**
     * GET /profile/rewards/transactions
     * Get paginated reward transaction history for the authenticated customer.
     */
    public function rewardTransactions(Request $request)
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 20);
        $type = $request->input('type');
        $search = $request->input('search');

        $account = RewardAccount::where('user_id', $user->id)->first();

        if (!$account) {
            return $this->paginatedResponse(
                new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, 1),
                'Transaction history retrieved'
            );
        }

        $query = RewardTransaction::where('reward_account_id', $account->id)
            ->with(['booking:id,campaign_title,status', 'admin:id,name']);

        if ($type) {
            $validTypes = ['earn', 'redeem', 'expire', 'admin_bonus', 'adjustment', 'refund_reversal'];
            if (in_array($type, $validTypes)) {
                $query->where('type', $type);
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        $transactions = $query->latest('created_at')->paginate($perPage);

        // Add running balance (calculated from the beginning of time)
        $allPoints = RewardTransaction::where('reward_account_id', $account->id)
            ->orderBy('created_at', 'asc')
            ->pluck('points', 'id');

        $runningBalance = 0;
        $runningBalances = [];
        foreach ($allPoints as $id => $pts) {
            $runningBalance += $pts;
            $runningBalances[$id] = $runningBalance;
        }

        $transactions->getCollection()->transform(function ($tx) use ($runningBalances) {
            return [
                'id' => $tx->id,
                'type' => $tx->type,
                'points' => $tx->points,
                'running_balance' => $runningBalances[$tx->id] ?? null,
                'description' => $tx->description,
                'reason' => $tx->reason,
                'booking' => $tx->booking ? [
                    'id' => $tx->booking->id,
                    'campaign_title' => $tx->booking->campaign_title,
                ] : null,
                'admin' => $tx->admin ? [
                    'id' => $tx->admin->id,
                    'name' => $tx->admin->name,
                ] : null,
                'created_at' => $tx->created_at?->toIso8601String(),
            ];
        });

        return $this->paginatedResponse($transactions, 'Transaction history retrieved');
    }

    /**
     * GET /profile/campaigns
     * Get active campaigns for the authenticated user.
     */
    public function campaigns(Request $request)
    {
        $filters = $request->only(['per_page']);
        $campaigns = $this->profileService->getActiveCampaigns($request->user(), $filters);
        return $this->paginatedResponse($campaigns, 'Active campaigns fetched successfully');
    }

    /**
     * GET /profile/history
     * Get completed/past campaigns for the authenticated user.
     */
    public function history(Request $request)
    {
        $filters = $request->only(['per_page', 'status']);
        $history = $this->profileService->getCampaignHistory($request->user(), $filters);
        return $this->paginatedResponse($history, 'Campaign history fetched successfully');
    }

    /**
     * GET /profile/campaigns/{id}
     * Get detailed campaign information.
     */
    public function show(Request $request, $id)
    {
        $details = $this->profileService->getCampaignDetails($request->user(), (int) $id);
        if (!$details) {
            return $this->errorResponse('Campaign not found', 404);
        }
        return $this->successResponse($details, 'Campaign details fetched successfully');
    }

    /**
     * POST /profile/avatar
     * Upload/update the authenticated user's avatar.
     */
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ]);

        $url = $this->profileService->uploadAvatar(
            $request->user(),
            $request->file('avatar')
        );

        return $this->successResponse([
            'url' => $url,
            'path' => $request->user()->fresh()->avatar,
        ], 'Avatar uploaded successfully');
    }

    /**
     * GET /profile/activity
     * Get recent activity timeline for the authenticated user.
     */
    public function activity(Request $request)
    {
        $limit = $request->input('limit', 20);
        $activity = $this->profileService->getRecentActivity(
            $request->user()->id,
            min((int) $limit, 50)
        );
        return $this->successResponse($activity, 'Activity fetched successfully');
    }
}
