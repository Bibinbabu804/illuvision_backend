<?php

namespace App\Http\Controllers;

use App\Application\Financial\UseCases\GenerateProviderSettlementUseCase;
use App\Models\ScreenProvider;
use App\Models\ScreenProviderSettlement;
use App\Services\SettlementService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminSettlementController
 *
 * Phase 0.10: settlement *generation* now goes through the Financial
 * application use case. The approve / mark-paid / cancel lifecycle transitions
 * remain on SettlementService, which owns the settlement state machine.
 */
class AdminSettlementController extends Controller
{
    public function __construct(
        private SettlementService $settlementService,
        private GenerateProviderSettlementUseCase $generateProviderSettlement,
    ) {
    }

    /**
     * GET /admin/settlements
     * Paginated list of all settlements with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->per_page ?? 10;
        $status = $request->status;
        $screenProviderId = $request->screen_provider_id;
        $dateFrom = $request->date_from;
        $dateTo = $request->date_to;
        $search = $request->search;
        $sortBy = $request->sort_by ?? 'created_at';
        $sortOrder = $request->sort_order ?? 'desc';

        $query = ScreenProviderSettlement::with([
            'screenProvider:id,provider_name,business_name',
            'generatedByUser:id,name',
            'approvedByUser:id,name',
            'paidByUser:id,name',
        ]);

        if ($status) {
            $query->where('status', $status);
        }

        if ($screenProviderId) {
            $query->where('screen_provider_id', $screenProviderId);
        }

        if ($dateFrom) {
            $query->where('period_end', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('period_start', '<=', $dateTo);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                  ->orWhereHas('screenProvider', function ($sq) use ($search) {
                      $sq->where('provider_name', 'like', "%{$search}%")
                         ->orWhere('business_name', 'like', "%{$search}%");
                  });
            });
        }

        $allowedSorts = ['created_at', 'period_start', 'period_end', 'gross_revenue', 'provider_revenue', 'status', 'reference_number'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }

        $settlements = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        $settlements->getCollection()->transform(function ($settlement) {
            $settlement->status_label = ScreenProviderSettlement::statuses()[$settlement->status] ?? $settlement->status;
            $settlement->provider_display_name = $settlement->screenProvider?->business_name
                ?: $settlement->screenProvider?->provider_name;
            return $settlement;
        });

        return $this->paginatedResponse($settlements, 'Settlements fetched successfully');
    }

    /**
     * GET /admin/settlements/{settlement}
     * Settlement details with included booking screens.
     */
    public function show(ScreenProviderSettlement $settlement): JsonResponse
    {
        $settlement->load([
            'screenProvider',
            'generatedByUser:id,name',
            'approvedByUser:id,name',
            'paidByUser:id,name',
            'bookingScreens' => function ($q) {
                $q->with(['booking' => function ($bq) {
                    $bq->select('id', 'campaign_title', 'advertiser_name', 'advertiser_email', 'start_time', 'end_time', 'status');
                }, 'screen' => function ($sq) {
                    $sq->select('id', 'name', 'screen_code');
                }])
                ->orderBy('created_at', 'desc');
            },
        ]);

        $settlement->status_label = ScreenProviderSettlement::statuses()[$settlement->status] ?? $settlement->status;
        $settlement->provider_display_name = $settlement->screenProvider?->business_name
            ?: $settlement->screenProvider?->provider_name;

        return $this->successResponse($settlement, 'Settlement fetched successfully');
    }

    /**
     * POST /admin/settlements/generate
     * Generate a new settlement for a screen provider for the given period.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'screen_provider_id' => 'required|exists:screen_providers,id',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'booking_id' => 'nullable|integer|exists:bookings,id',
        ]);

        try {
            $settlement = $this->generateProviderSettlement->execute(
                $validated['screen_provider_id'],
                $validated['period_start'],
                $validated['period_end'],
                $request->user(),
                $validated['booking_id'] ?? null
            );

            return $this->successResponse($settlement, 'Settlement generated successfully', 201);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to generate settlement: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /admin/settlements/{settlement}
     * Update settlement notes and reference number.
     */
    public function update(Request $request, ScreenProviderSettlement $settlement): JsonResponse
    {
        if (!$settlement->isEditable()) {
            return $this->errorResponse('Settlement cannot be edited in its current status.', 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:5000',
        ]);

        try {
            $settlement->update([
                'notes' => $validated['notes'] ?? $settlement->notes,
            ]);

            return $this->successResponse($settlement->fresh(), 'Settlement updated successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update settlement: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /admin/settlements/{settlement}/approve
     * Approve a generated settlement.
     */
    public function approve(Request $request, ScreenProviderSettlement $settlement): JsonResponse
    {
        try {
            $settlement = $this->settlementService->approveSettlement($settlement, $request->user());
            return $this->successResponse($settlement, 'Settlement approved successfully');
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to approve settlement: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /admin/settlements/{settlement}/mark-paid
     * Mark an approved settlement as paid.
     */
    public function markPaid(Request $request, ScreenProviderSettlement $settlement): JsonResponse
    {
        $validated = $request->validate([
            'reference_number' => 'nullable|string|max:255',
        ]);

        try {
            $settlement = $this->settlementService->markAsPaid(
                $settlement,
                $request->user(),
                $validated['reference_number'] ?? null
            );
            return $this->successResponse($settlement, 'Settlement marked as paid successfully');
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to mark settlement as paid: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /admin/settlements/{settlement}/cancel
     * Cancel a settlement and revert booking screens to pending.
     */
    public function cancel(Request $request, ScreenProviderSettlement $settlement): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $settlement = $this->settlementService->cancelSettlement(
                $settlement,
                $validated['reason'] ?? null
            );
            return $this->successResponse($settlement, 'Settlement cancelled successfully');
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to cancel settlement: ' . $e->getMessage(), 500);
        }
    }
}
