<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\RewardAccount;
use App\Models\RewardTransaction;
use App\Services\Campaign\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRewardsController extends Controller
{
    protected LoyaltyService $loyaltyService;

    public function __construct(LoyaltyService $loyaltyService)
    {
        $this->loyaltyService = $loyaltyService;
    }

    /**
     * GET /admin/rewards
     * List all customer reward balances with pagination and search.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);

        $query = User::where('role', 'client')
            ->with('rewardAccount');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->paginate($perPage);

        // Map data to summary resource format
        $users->getCollection()->transform(function ($user) {
            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'balance' => $user->rewardAccount?->balance ?? 0,
                'lifetime_earned' => $user->rewardAccount?->lifetime_earned ?? 0,
                'lifetime_redeemed' => $user->rewardAccount?->lifetime_redeemed ?? 0,
                'lifetime_expired' => $user->rewardAccount?->lifetime_expired ?? 0,
                'last_activity_at' => $user->rewardAccount?->updated_at?->toIso8601String(),
            ];
        });

        return $this->paginatedResponse($users, 'Customer reward accounts retrieved');
    }

    /**
     * GET /admin/rewards/{userId}
     * Retrieve transaction history and balance details for a specific customer.
     */
    public function show(Request $request, $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $account = RewardAccount::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0, 'lifetime_expired' => 0]
        );

        $perPage = $request->input('per_page', 15);
        $transactions = RewardTransaction::where('reward_account_id', $account->id)
            ->with(['booking', 'admin'])
            ->latest('created_at')
            ->paginate($perPage);

        return $this->successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'account' => [
                'balance' => $account->balance,
                'lifetime_earned' => $account->lifetime_earned,
                'lifetime_redeemed' => $account->lifetime_redeemed,
                'lifetime_expired' => $account->lifetime_expired,
            ],
            'transactions' => $transactions,
        ], 'Customer transaction details retrieved');
    }

    /**
     * POST /admin/rewards/adjust
     * Admin override to adjust client reward points.
     */
    public function adjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'points' => 'required|integer|not_in:0',
            'type' => 'required|string|in:adjustment,admin_bonus,refund_reversal',
            'reason' => 'required|string|min:3|max:255',
        ]);

        $user = User::findOrFail($validated['user_id']);
        
        $this->loyaltyService->adjustPoints(
            $user,
            (int) $validated['points'],
            $validated['type'],
            $validated['reason'],
            $request->user()->id
        );

        $account = RewardAccount::where('user_id', $user->id)->first();

        return $this->successResponse([
            'user_id' => $user->id,
            'balance' => $account?->balance ?? 0,
        ], 'Reward points adjusted successfully');
    }
}
