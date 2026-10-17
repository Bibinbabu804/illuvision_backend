<?php

namespace App\Http\Controllers;
use App\Support\Events\Notifications\PartnerCreated;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\ReferralCode;
use App\Models\ReferralUsage;
use App\Services\PartnerService;
use App\Services\Campaign\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerController extends Controller
{
    protected ReferralService $referralService;
    protected PartnerService $partnerService;

    public function __construct(ReferralService $referralService, PartnerService $partnerService)
    {
        $this->referralService = $referralService;
        $this->partnerService = $partnerService;
    }

    /**
     * List active/approved partners with metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $perPage = $request->input('perPage', 10);
        $status = $request->input('status');

        $partners = Partner::query()
            ->withCount([
                'referralCodes',
                'commissions as total_bookings' => function ($q) {
                    $q->select(DB::raw('COALESCE(COUNT(DISTINCT referral_usage_id), 0)'));
                }
            ])
            ->withCount([
                'commissions as revenue_generated' => function ($q) {
                    $q->select(DB::raw('COALESCE(SUM(amount), 0)'));
                }
            ])
            ->withCount([
                'commissions as commission_earned' => function ($q) {
                    $q->select(DB::raw('COALESCE(SUM(amount), 0)'));
                }
            ])
            ->with(['referralCodes' => function ($q) {
                $q->where('status', 'active')->limit(1);
            }])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhereHas('referralCodes', function ($rq) use ($search) {
                          $rq->where('code', 'like', "%{$search}%");
                      });
                });
            })
            ->when($status, function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->paginatedResponse($partners, 'Partners fetched successfully');
    }

    /**
     * Get all partners (including applications) for dropdowns.
     */
    public function allPartners(): JsonResponse
    {
        $partners = Partner::select('id', 'name', 'email', 'status')
            ->orderBy('name')
            ->get();

        return $this->successResponse($partners, 'All partners fetched successfully');
    }

    /**
     * Create a partner manually (admin direct creation without application flow).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:partners,email',
            'phone' => 'required|string|max:50',
            'status' => 'required|in:active,inactive,suspended',
            'notes' => 'nullable|string|max:1000',
        ]);

        $partner = Partner::create($validated);

        // Dispatch notification event
        event(new PartnerCreated($partner));

        return $this->successResponse($partner, 'Partner created successfully', 201);
    }

    /**
     * Create partner WITH full onboarding (user account + referral code + setup link).
     * Used for admin to create a fully onboarded partner.
     */
    public function createWithOnboarding(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:partners,email',
            'phone' => 'required|string|max:50',
            'city' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'profession' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $admin = $request->user();

        try {
            // Create partner in pending status first
            $partner = Partner::create(array_merge($validated, [
                'status' => Partner::STATUS_PENDING,
            ]));

            // Auto-approve to trigger full onboarding
            $result = $this->partnerService->approve($partner, $admin);

            return $this->successResponse([
                'partner' => $result['partner'],
                'user' => $result['user'],
                'referral_code' => $result['referral_code'],
                'setup_token' => $result['setup_token'],
            ], 'Partner created and onboarded successfully. Welcome email sent.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create partner: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get full partner detail with all related data.
     */
    public function show(Partner $partner): JsonResponse
    {
        $partner->loadCount(['referralCodes', 'commissions']);
        $partner->load([
            'referralCodes' => function ($q) {
                $q->withCount('usages')->orderBy('created_at', 'desc');
            },
            'user',
            'reviewer',
        ]);

        // Get summary stats
        $commissionSummary = $this->referralService->getPartnerCommissionSummary($partner->id);

        $recentUsages = ReferralUsage::whereHas('referralCode', function ($q) use ($partner) {
            $q->where('partner_id', $partner->id);
        })
            ->with(['referralCode', 'commission', 'booking'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $revenueGenerated = ReferralUsage::whereHas('referralCode', function ($q) use ($partner) {
            $q->where('partner_id', $partner->id);
        })
            ->whereIn('status', ['confirmed', 'paid'])
            ->sum('discount_amount');

        // Performance metrics
        $totalReferrals = $partner->referralCodes->sum('used_count');
        $successfulReferrals = ReferralUsage::whereHas('referralCode', function ($q) use ($partner) {
            $q->where('partner_id', $partner->id);
        })->whereIn('status', ['confirmed', 'paid'])->count();

        $totalBookingValue = ReferralUsage::whereHas('referralCode', function ($q) use ($partner) {
            $q->where('partner_id', $partner->id);
        })->whereIn('status', ['confirmed', 'paid'])->sum('final_booking_amount');

        // Set attributes directly on the partner model for frontend compatibility
        $partner->setAttribute('commission_summary', $commissionSummary);
        $partner->setAttribute('revenue_generated', (float) $revenueGenerated);
        $partner->setAttribute('recent_bookings', $recentUsages->map(function ($usage) {
            $booking = $usage->booking;
            return [
                'id' => $usage->id,
                'booking_id' => $booking?->id ?? 'N/A',
                'booking_reference' => $booking?->booking_id ?? $booking?->id ?? 'N/A',
                'client_name' => $booking?->user?->name ?? 'N/A',
                'screen_name' => $booking?->screen?->name ?? 'N/A',
                'referral_code' => $usage->referralCode?->code ?? 'N/A',
                'discount_amount' => (float) $usage->discount_amount,
                'commission_amount' => (float) $usage->commission_amount,
                'final_amount' => (float) $usage->final_booking_amount,
                'status' => $usage->status,
                'created_at' => $usage->created_at?->format('Y-m-d H:i:s'),
            ];
        }));
        $partner->setAttribute('referral_performance', [
            'total_referrals' => $totalReferrals,
            'successful_referrals' => $successfulReferrals,
            'conversion_rate' => $totalReferrals > 0
                ? round(($successfulReferrals / $totalReferrals) * 100, 1)
                : 0,
            'total_booking_value' => (float) $totalBookingValue,
            'total_commission' => (float) $commissionSummary['total_earned'] ?? 0,
            'pending_commission' => (float) $commissionSummary['pending'] ?? 0,
            'paid_commission' => (float) $commissionSummary['paid'] ?? 0,
        ]);

        return $this->successResponse($partner, 'Partner details fetched successfully');
    }

    /**
     * Update partner details.
     */
    public function update(Request $request, Partner $partner): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_person' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:partners,email,' . $partner->id,
            'phone' => 'sometimes|required|string|max:50',
            'city' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'profession' => 'nullable|string|max:255',
            'status' => 'sometimes|required|in:active,inactive,suspended',
            'notes' => 'nullable|string|max:1000',
        ]);

        $partner->update($validated);

        return $this->successResponse($partner->fresh(), 'Partner updated successfully');
    }

    /**
     * Delete a partner.
     */
    public function destroy(Partner $partner): JsonResponse
    {
        $partner->referralCodes()->delete();
        $partner->commissions()->delete();
        $partner->delete();
        return $this->successResponse(null, 'Partner deleted successfully');
    }

    /**
     * Update partner status (activate, deactivate, suspend).
     */
    public function updateStatus(Request $request, Partner $partner): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,suspended',
            'reason' => 'nullable|string|max:500',
        ]);

        $oldStatus = $partner->status;
        $partner->update([
            'status' => $validated['status'],
            'notes' => $validated['reason']
                ? ($partner->notes ? $partner->notes . "\n\n[" . now()->format('Y-m-d H:i') . "] Status changed from {$oldStatus} to {$validated['status']}: " . $validated['reason'] : "[" . now()->format('Y-m-d H:i') . "] Status changed from {$oldStatus} to {$validated['status']}: " . $validated['reason'])
                : $partner->notes,
        ]);

        return $this->successResponse(
            $partner->fresh(),
            "Partner status updated to {$validated['status']} successfully"
        );
    }

    /**
     * Get partner activity timeline.
     */
    public function timeline(Partner $partner): JsonResponse
    {
        $activities = $this->partnerService->getActivityTimeline($partner, 30);
        return $this->successResponse($activities, 'Timeline fetched successfully');
    }

    /**
     * Get partner's referral bookings (paginated).
     */
    public function bookings(Request $request, Partner $partner): JsonResponse
    {
        $perPage = $request->input('perPage', 15);
        $result = $this->partnerService->getReferralBookings($partner, $perPage);
        return $this->successResponse($result, 'Referral bookings fetched successfully');
    }

    /**
     * Get partner's commissions (paginated).
     */
    public function commissions(Request $request, Partner $partner): JsonResponse
    {
        $perPage = $request->input('perPage', 15);
        $result = $this->partnerService->getCommissions($partner, $perPage);
        return $this->successResponse($result, 'Commissions fetched successfully');
    }

    /**
     * Get dashboard KPIs for partners module.
     */
    public function kpis(): JsonResponse
    {
        $totalPartners = Partner::count();
        $activePartners = Partner::where('status', Partner::STATUS_APPROVED)->count();
        $activeReferralCodes = ReferralCode::where('status', 'active')->count();
        $referralRevenue = ReferralUsage::whereIn('status', ['confirmed', 'paid'])->sum('discount_amount');
        $pendingCommissions = PartnerCommission::where('status', 'pending')->sum('amount');
        $paidCommissions = PartnerCommission::where('status', 'paid')->sum('amount');
        $totalReferrals = ReferralCode::sum('used_count');

        return $this->successResponse([
            'total_partners' => $totalPartners,
            'active_partners' => $activePartners,
            'active_referral_codes' => $activeReferralCodes,
            'referral_revenue' => (float) $referralRevenue,
            'pending_commissions' => (float) $pendingCommissions,
            'paid_commissions' => (float) $paidCommissions,
            'total_referrals' => (float) $totalReferrals,
        ], 'KPIs fetched successfully');
    }
}
