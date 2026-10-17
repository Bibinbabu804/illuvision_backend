<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ScreenProviderSettlement;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Exception;
use App\Services\Settlement\RevenueAnalyticsService;

class FinanceService
{
    protected PaymentService $paymentService;
    protected SettlementService $settlementService;
    protected RefundService $refundService;
    protected CommissionService $commissionService;
    protected RevenueAnalyticsService $revenueAnalyticsService;

    public function __construct(
        PaymentService $paymentService,
        SettlementService $settlementService,
        RefundService $refundService,
        CommissionService $commissionService,
        RevenueAnalyticsService $revenueAnalyticsService
    ) {
        $this->paymentService = $paymentService;
        $this->settlementService = $settlementService;
        $this->refundService = $refundService;
        $this->commissionService = $commissionService;
        $this->revenueAnalyticsService = $revenueAnalyticsService;
    }

    /**
     * Get high-level financial summary KPIs and totals.
     */
    public function getFinancialSummary(): array
    {
        $totalGrossRevenue = (float) Booking::whereIn('status', ['approved', 'scheduled', 'running', 'completed'])
            ->sum('final_amount');

        $totalPaymentsReceived = (float) Payment::where('payment_status', 'paid')
            ->sum('amount');

        $totalSettledToProviders = (float) ScreenProviderSettlement::where('status', 'paid')
            ->sum('provider_revenue');

        $pendingSettlementsCount = ScreenProviderSettlement::where('status', 'pending')->count();

        $totalPlatformCommission = (float) Booking::whereIn('status', ['approved', 'scheduled', 'running', 'completed'])
            ->selectRaw('SUM(final_amount - (final_amount * 0.70)) as commission')
            ->value('commission') ?? 0.0;

        $totalRefundsIssued = (float) Refund::where('status', 'processed')
            ->sum('amount');

        return [
            'gross_revenue' => round($totalGrossRevenue, 2),
            'payments_received' => round($totalPaymentsReceived, 2),
            'provider_settlements_paid' => round($totalSettledToProviders, 2),
            'pending_settlements_count' => $pendingSettlementsCount,
            'platform_commission' => round($totalPlatformCommission, 2),
            'refunds_issued' => round($totalRefundsIssued, 2),
            'currency' => 'INR',
        ];
    }

    /**
     * List payments with optional filters.
     */
    public function getPayments(array $filters = []): array
    {
        $query = Payment::with(['booking']);

        if (!empty($filters['status'])) {
            $query->where('payment_status', $filters['status']);
        }

        if (!empty($filters['booking_id'])) {
            $query->where('booking_id', $filters['booking_id']);
        }

        $perPage = $filters['per_page'] ?? 20;

        return $query->orderBy('id', 'desc')->paginate($perPage)->toArray();
    }

    /**
     * List settlements with optional filters.
     */
    public function getSettlements(array $filters = []): array
    {
        $query = ScreenProviderSettlement::with(['screenProvider']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['provider_id'])) {
            $query->where('screen_provider_id', $filters['provider_id']);
        }

        $perPage = $filters['per_page'] ?? 20;

        return $query->orderBy('id', 'desc')->paginate($perPage)->toArray();
    }

    /**
     * Process bulk settlement batch transition.
     */
    public function processSettlementBatch(array $settlementIds, string $targetStatus, ?string $notes = null): int
    {
        return DB::transaction(function () use ($settlementIds, $targetStatus, $notes) {
            $count = 0;
            $settlements = ScreenProviderSettlement::whereIn('id', $settlementIds)->get();

            foreach ($settlements as $settlement) {
                $settlement->status = $targetStatus;
                if ($notes) {
                    $settlement->notes = $notes;
                }
                if ($targetStatus === 'paid') {
                    $settlement->paid_at = now();
                }
                $settlement->save();
                $count++;

                // Log audit trail
                ActivityLog::create([
                    'log_name' => 'finance_settlement',
                    'description' => "Settlement #{$settlement->reference_number} updated to {$targetStatus}",
                    'subject_type' => ScreenProviderSettlement::class,
                    'subject_id' => $settlement->id,
                    'properties' => [
                        'new_status' => $targetStatus,
                        'notes' => $notes,
                    ],
                ]);
            }

            return $count;
        });
    }

    /**
     * Get financial accounting ledger records.
     */
    public function getAccountingLedger(): array
    {
        $payments = Payment::where('payment_status', 'paid')
            ->select('id', 'booking_id', 'amount', 'paid_at as transaction_date')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($p) {
                return [
                    'id' => 'PAY-' . $p->id,
                    'type' => 'income',
                    'category' => 'Customer Campaign Payment',
                    'amount' => (float) $p->amount,
                    'reference' => 'Booking #' . $p->booking_id,
                    'date' => $p->transaction_date ? $p->transaction_date->toIso8601String() : now()->toIso8601String(),
                ];
            });

        $settlements = ScreenProviderSettlement::where('status', 'paid')
            ->select('id', 'reference_number', 'provider_revenue', 'paid_at as transaction_date')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($s) {
                return [
                    'id' => 'SETT-' . $s->id,
                    'type' => 'expense',
                    'category' => 'Provider Settlement Payout',
                    'amount' => (float) $s->provider_revenue,
                    'reference' => 'Settlement #' . $s->reference_number,
                    'date' => $s->transaction_date ? $s->transaction_date->toIso8601String() : now()->toIso8601String(),
                ];
            });

        return array_merge($payments->toArray(), $settlements->toArray());
    }
}
