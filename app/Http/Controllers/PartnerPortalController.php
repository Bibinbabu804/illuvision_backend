<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Services\PartnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerPortalController extends Controller
{
    public function __construct(
        private PartnerService $partnerService
    ) {}

    /**
     * Get partner dashboard overview.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner not found.', 404);
        }

        $summary = $this->partnerService->getDashboardSummary($partner);
        $activity = $this->partnerService->getActivityTimeline($partner, 10);

        return $this->successResponse([
            'summary' => $summary,
            'recent_activity' => $activity,
        ],'Dashboard data retrieved.');
    }

    /**
     * Get partner's referral code.
     */
    public function referralCode(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner not found.', 404);
        }

        $codes = $partner->referralCodes()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($code) {
                return [
                    'id' => $code->id,
                    'code' => $code->code,
                    'status' => $code->status,
                    'created_date' => $code->created_at->format('Y-m-d H:i:s'),
                    'usage_count' => $code->used_count,
                    'usage_limit' => $code->usage_limit,
                    'discount_type' => $code->discount_type,
                    'discount_value' => (float) $code->discount_value,
                ];
            });

        $activeCode = $codes->firstWhere('status', 'active');

        return $this->successResponse([
            'codes' => $codes,
            'active_code' => $activeCode,
        ],'Referral codes retrieved.');
    }

    /**
     * Get partner's referral bookings.
     */
    public function bookings(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner not found.', 404);
        }

        $perPage = $request->input('per_page', 15);
        $result = $this->partnerService->getReferralBookings($partner, $perPage);

        return $this->successResponse( $result,'Referral bookings retrieved.');
    }

    /**
     * Get partner's commissions.
     */
    public function commissions(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner not found.', 404);
        }

        $perPage = $request->input('per_page', 15);
        $status = $request->input('status');
        $result = $this->partnerService->getCommissions($partner, $perPage, $status);

        return $this->successResponse( $result,'Commissions retrieved.');
    }

    /**
     * Update partner profile.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner not found.', 404);
        }

        $validated = $request->validate([
            'phone' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
        ]);

        $partner->update($validated);

        // Also update the user's phone if provided
        if ($request->filled('phone') && $partner->user) {
            $partner->user->update(['phone' => $request->phone]);
        }

        return $this->successResponse( ['partner' => $partner->fresh(),],'Profile updated.');
    }

    /**
     * Setup password using token.
     */
    public function setupPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $success = $this->partnerService->setupPassword(
            $validated['email'],
            $validated['token'],
            $validated['password']
        );

        if (!$success) {
            return $this->errorResponse('Invalid or expired token.', 400);
        }

        return $this->successResponse('Password has been set successfully. You can now login.');
    }

    /**
     * Get aggregated partner reports across Bookings, Campaigns, Payments, Revenue, Commissions, and Settlements.
     * GET /partner/reports
     */
    public function reports(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner not found or access denied.', 403);
        }

        $type = $request->query('type', 'revenue'); // bookings, campaigns, payments, revenue, commissions, settlements
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $bookingsQuery = \App\Models\Booking::where('created_by_partner_id', $partner->id)
            ->when($dateFrom, fn($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('created_at', '<=', $dateTo));

        $bookingIds = $bookingsQuery->pluck('id');

        $reportData = match ($type) {
            'bookings', 'campaigns' => $bookingsQuery->with(['user:id,name,email', 'bookingScreens.screen'])->latest()->get(),
            'payments' => \App\Models\Payment::whereIn('booking_id', $bookingIds)->with('booking:id,title,final_amount')->latest()->get(),
            'commissions' => $partner->commissions()->with('booking:id,title,final_amount')->latest()->get()->map(function ($c) {
                return [
                    'id' => $c->id,
                    'booking_id' => $c->booking_id,
                    'campaign_title' => $c->booking?->title ?? "DOOH Campaign #{$c->booking_id}",
                    'status' => $c->status,
                    'created_at' => $c->created_at,
                    'campaign_total' => (float) ($c->campaign_total > 0 ? $c->campaign_total : ($c->booking?->final_amount ?? $c->amount)),
                    'provider_revenue' => (float) $c->provider_revenue,
                    'illuvision_revenue' => (float) ($c->illuvision_revenue > 0 ? $c->illuvision_revenue : ($c->campaign_total - $c->provider_revenue)),
                    'commission_base' => (float) ($c->partner_commission_base > 0 ? $c->partner_commission_base : ($c->illuvision_revenue > 0 ? $c->illuvision_revenue : $c->campaign_total)),
                    'commission_percentage' => (float) ($c->partner_commission_percentage > 0 ? $c->partner_commission_percentage : $c->commission_value),
                    'commission_amount' => (float) $c->amount,
                    'amount' => (float) $c->amount,
                ];
            }),
            'settlements' => [
                'total_settled_amount' => (float) $partner->commissions()->where('status', 'paid')->sum('amount'),
                'pending_settlement_amount' => (float) $partner->commissions()->where('status', 'approved')->sum('amount'),
                'records' => $partner->commissions()->with('booking:id,title')->latest()->get(),
            ],
            default => [
                'total_gross_revenue' => (float) $bookingsQuery->where('status', '!=', \App\Models\Booking::STATUS_CANCELLED)->sum('final_amount'),
                'total_commissions_earned' => (float) $partner->commissions()->sum('amount'),
                'bookings_count' => $bookingsQuery->count(),
            ],
        };

        return $this->successResponse($reportData, ucfirst($type) . ' report retrieved successfully.');
    }

    /**
     * Export partner report in CSV or JSON format.
     * GET /partner/reports/export?type=...&format=csv|json|excel
     */
    public function exportReport(Request $request)
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner not found or access denied.', 403);
        }

        $type = $request->query('type', 'revenue');
        $format = strtolower($request->query('format', 'csv'));

        $bookings = \App\Models\Booking::where('created_by_partner_id', $partner->id)->with('user')->get();

        if ($format === 'csv' || $format === 'excel') {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=partner_{$type}_report.csv",
            ];

            $callback = function () use ($bookings) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Booking ID', 'Campaign Title', 'Client Name', 'Status', 'Final Amount', 'Created At']);

                foreach ($bookings as $b) {
                    fputcsv($file, [
                        $b->id,
                        $b->title,
                        $b->user?->name ?? 'N/A',
                        $b->status,
                        $b->final_amount,
                        $b->created_at->format('Y-m-d H:i:s'),
                    ]);
                }
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        }

        return $this->successResponse($bookings, 'Report dataset exported.');
    }

    /**
     * Get the authenticated partner from the request user.
     */
    private function getAuthenticatedPartner(Request $request): ?Partner
    {
        $user = $request->user();
        if (!$user || $user->role !== 'partner') {
            return null;
        }

        return Partner::where('user_id', $user->id)->where('status', Partner::STATUS_APPROVED)->first();
    }
}
