<?php

namespace App\Http\Controllers;
use App\Support\Events\Notifications\CommissionGenerated;
use App\Models\Partner;
use App\Models\ReferralCode;
use App\Models\ReferralUsage;
use App\Models\PartnerCommission;
use App\Services\CommissionService;
use App\Services\Campaign\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralAnalyticsController extends Controller
{
    protected ReferralService $referralService;
    protected CommissionService $commissionService;

    public function __construct(ReferralService $referralService, CommissionService $commissionService)
    {
        $this->referralService = $referralService;
        $this->commissionService = $commissionService;
    }

    /**
     * Get overall referral analytics.
     */
    public function overview(): JsonResponse
    {
        $analytics = $this->referralService->getAnalytics();
        return $this->successResponse($analytics, 'Analytics fetched successfully');
    }

    /**
     * Get commission summary.
     */
    public function commissionSummary(): JsonResponse
    {
        $summary = $this->commissionService->getSummary();
        return $this->successResponse($summary, 'Commission summary fetched successfully');
    }

    /**
     * List all commissions with filters.
     */
    public function commissions(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'partner_id', 'date_from', 'date_to', 'search', 'per_page']);
        $commissions = $this->commissionService->getAllCommissions($filters);
        return $this->paginatedResponse($commissions, 'Commissions fetched successfully');
    }

    /**
     * Get single commission detail with full relationships.
     */
    public function commissionDetail(int $id): JsonResponse
    {
        $commission = $this->commissionService->getCommission($id);
        $timeline = $this->commissionService->getStatusTimeline($commission);

        return $this->successResponse([
            'commission' => $commission,
            'timeline' => $timeline,
        ], 'Commission details fetched successfully');
    }

    /**
     * Approve a commission.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $commission = $this->commissionService->approve(
            $id,
            $request->user()->id,
            $request->input('notes')
        );

        // Dispatch notification event
        $commission->load(['partner', 'referralUsage.referralCode']);
        event(new CommissionGenerated($commission));

        return $this->successResponse($commission, 'Commission approved successfully');
    }

    /**
     * Mark commission as paid.
     */
    public function markPaid(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
            'payout_date' => 'nullable|date',
            'payout_reference' => 'nullable|string|max:100',
            'payout_method' => 'nullable|string|max:50',
            'payout_notes' => 'nullable|string|max:500',
        ]);

        $payoutData = $request->only(['payout_date', 'payout_reference', 'payout_method', 'payout_notes']);

        $commission = $this->commissionService->markAsPaid(
            $id,
            $request->input('notes'),
            $payoutData
        );

        // Dispatch notification event
        $commission->load(['partner', 'referralUsage.referralCode']);
        event(new CommissionGenerated($commission));

        return $this->successResponse($commission, 'Commission marked as paid');
    }

    /**
     * Mark commission as cancelled.
     */
    public function markCancelled(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $commission = $this->commissionService->markAsCancelled(
            $id,
            $request->input('notes')
        );

        return $this->successResponse($commission, 'Commission marked as cancelled');
    }

    /**
     * Top performing partners.
     */
    public function topPartners(): JsonResponse
    {
        $topPartners = Partner::withCount(['referralCodes', 'commissions'])
            ->withSum('commissions', 'amount')
            ->orderByDesc('commissions_sum_amount')
            ->limit(10)
            ->get();

        return $this->successResponse($topPartners, 'Top partners fetched successfully');
    }

    /**
     * Top referral codes.
     */
    public function topCodes(): JsonResponse
    {
        $topCodes = ReferralCode::with('partner')
            ->withCount('usages')
            ->withSum('usages', 'discount_amount')
            ->withSum('usages', 'commission_amount')
            ->orderByDesc('usages_count')
            ->limit(10)
            ->get();

        return $this->successResponse($topCodes, 'Top codes fetched successfully');
    }
}
