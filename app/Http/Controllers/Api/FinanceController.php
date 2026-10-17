<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class FinanceController extends Controller
{
    protected FinanceService $financeService;

    public function __construct(FinanceService $financeService)
    {
        $this->financeService = $financeService;
    }

    /**
     * Financial summary KPIs.
     * GET /api/v2/finance/summary
     */
    public function summary(): JsonResponse
    {
        $data = $this->financeService->getFinancialSummary();

        return response()->json([
            'status' => true,
            'message' => 'Financial summary retrieved successfully',
            'data' => $data,
        ]);
    }

    /**
     * Payment transactions list.
     * GET /api/v2/finance/payments
     */
    public function payments(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'booking_id', 'per_page']);
        $data = $this->financeService->getPayments($filters);

        return response()->json([
            'status' => true,
            'message' => 'Payments retrieved successfully',
            'data' => $data,
        ]);
    }

    /**
     * Provider settlements list.
     * GET /api/v2/finance/settlements
     */
    public function settlements(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'provider_id', 'per_page']);
        $data = $this->financeService->getSettlements($filters);

        return response()->json([
            'status' => true,
            'message' => 'Settlements retrieved successfully',
            'data' => $data,
        ]);
    }

    /**
     * Batch update settlements.
     * POST /api/v2/finance/settlements/batch
     */
    public function batchSettlements(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settlement_ids' => 'required|array|min:1',
            'settlement_ids.*' => 'integer|exists:screen_provider_settlements,id',
            'status' => 'required|string|in:approved,scheduled,processing,paid,rejected,cancelled',
            'notes' => 'nullable|string|max:500',
        ]);

        $updatedCount = $this->financeService->processSettlementBatch(
            $validated['settlement_ids'],
            $validated['status'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'status' => true,
            'message' => "Successfully updated {$updatedCount} settlements to status {$validated['status']}",
            'data' => [
                'updated_count' => $updatedCount,
                'target_status' => $validated['status'],
            ],
        ]);
    }

    /**
     * Accounting ledger entries.
     * GET /api/v2/finance/ledger
     */
    public function ledger(): JsonResponse
    {
        $data = $this->financeService->getAccountingLedger();

        return response()->json([
            'status' => true,
            'message' => 'Accounting ledger retrieved successfully',
            'data' => $data,
        ]);
    }
}
