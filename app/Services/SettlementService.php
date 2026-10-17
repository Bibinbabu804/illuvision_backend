<?php

namespace App\Services;

use App\Domain\Financial\Contracts\SettlementRepositoryInterface;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\Screen;
use App\Models\ScreenProvider;
use App\Models\ScreenProviderActivity;
use App\Models\ScreenProviderSettlement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for generating and managing Screen Provider settlements.
 *
 * All financial calculations are sourced from immutable booking_screen revenue snapshots.
 * Never uses current Screen Provider percentages or live Screen ownership.
 *
 * PHASE 0.10 STRUCTURAL CHANGE (behaviour-preserving):
 * The eligibility query in `generateSettlement()` moved verbatim into
 * `SettlementRepositoryInterface` / `EloquentSettlementRepository`, isolating the
 * most complex aggregate read in the financial subsystem behind a persistence
 * boundary. Predicates, ordering, hydration and the `RuntimeException` contract
 * are unchanged. Settlement lifecycle transitions remain here.
 */
class SettlementService
{
    public function __construct(
        protected SettlementRepositoryInterface $settlementRepository,
    ) {}

    /**
     * Generate a new settlement for a screen provider for the given period.
     *
     * Finds all eligible booking_screens where:
     * - Owner Type = Screen Provider
     * - Settlement Status = Pending
     * - Screen belongs to the given provider
     * - Booking falls within the period
     *
     * @param int $screenProviderId
     * @param string $periodStart  Y-m-d
     * @param string $periodEnd    Y-m-d
     * @param User $generatedBy
     * @return ScreenProviderSettlement
     */
    public function generateSettlement(int $screenProviderId, string $periodStart, string $periodEnd, User $generatedBy, ?int $bookingId = null): ScreenProviderSettlement
    {
        $periodStart = Carbon::parse($periodStart)->startOfDay();
        $periodEnd = Carbon::parse($periodEnd)->endOfDay();

        // Find eligible booking_screens for this provider within the period
        $eligibleScreens = $this->settlementRepository->findEligibleBookingScreens(
            $screenProviderId,
            $periodStart,
            $periodEnd,
            $bookingId
        );
        $eligibleCount = $eligibleScreens->count();

        if ($eligibleCount === 0) {
            throw new \RuntimeException($bookingId ? 'No eligible booking screens found for this booking.' : 'No eligible booking screens found for the given period.');
        }

        // Calculate totals from snapshots
        $grossRevenue = (float) $eligibleScreens->sum('gross_revenue');
        $platformRevenue = (float) $eligibleScreens->sum('platform_share');
        $providerRevenue = (float) $eligibleScreens->sum('provider_share');
        $bookingCount = $eligibleScreens->pluck('booking_id')->unique()->count();
        $screenCount = $eligibleScreens->pluck('screen_id')->unique()->count();

        // Generate a unique reference number
        $referenceNumber = 'SET-' . str_pad($screenProviderId, 4, '0', STR_PAD_LEFT) . '-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        // Create settlement in a transaction
        $settlement = DB::transaction(function () use (
            $screenProviderId, $periodStart, $periodEnd,
            $grossRevenue, $platformRevenue, $providerRevenue,
            $bookingCount, $screenCount, $referenceNumber, $generatedBy, $eligibleScreens
        ) {
            $settlement = ScreenProviderSettlement::create([
                'screen_provider_id' => $screenProviderId,
                'period_start' => $periodStart->format('Y-m-d'),
                'period_end' => $periodEnd->format('Y-m-d'),
                'gross_revenue' => $grossRevenue,
                'platform_revenue' => $platformRevenue,
                'provider_revenue' => $providerRevenue,
                'booking_count' => $bookingCount,
                'screen_count' => $screenCount,
                'status' => ScreenProviderSettlement::STATUS_GENERATED,
                'reference_number' => $referenceNumber,
                'generated_by' => $generatedBy->id,
            ]);

            // Update linked booking_screens
            foreach ($eligibleScreens as $bookingScreen) {
                $bookingScreen->update([
                    'settlement_id' => $settlement->id,
                    'settlement_status' => 'settled',
                    'settled_at' => now(),
                ]);
            }

            // Log activity for the provider
            ScreenProviderActivity::create([
                'screen_provider_id' => $screenProviderId,
                'type' => 'settlement_generated',
                'description' => sprintf(
                    'Settlement #%s generated for period %s to %s — Gross: ₹%s, Provider: ₹%s, %d booking(s), %d screen(s)',
                    $referenceNumber,
                    $periodStart->format('d M Y'),
                    $periodEnd->format('d M Y'),
                    number_format($grossRevenue, 2),
                    number_format($providerRevenue, 2),
                    $bookingCount,
                    $screenCount
                ),
                'metadata' => [
                    'settlement_id' => $settlement->id,
                    'reference_number' => $referenceNumber,
                    'period_start' => $periodStart->format('Y-m-d'),
                    'period_end' => $periodEnd->format('Y-m-d'),
                    'gross_revenue' => $grossRevenue,
                    'platform_revenue' => $platformRevenue,
                    'provider_revenue' => $providerRevenue,
                    'booking_count' => $bookingCount,
                    'screen_count' => $screenCount,
                ],
            ]);

            return $settlement;
        });

        return $settlement->fresh(['screenProvider', 'generatedByUser']);
    }

    /**
     * Approve a settlement.
     */
    public function approveSettlement(ScreenProviderSettlement $settlement, User $approvedBy): ScreenProviderSettlement
    {
        if (!$settlement->canBeApproved()) {
            throw new \RuntimeException('Settlement cannot be approved in its current status: ' . $settlement->status);
        }

        $settlement->update([
            'status' => ScreenProviderSettlement::STATUS_APPROVED,
            'approved_by' => $approvedBy->id,
        ]);

        ScreenProviderActivity::create([
            'screen_provider_id' => $settlement->screen_provider_id,
            'type' => 'settlement_approved',
            'description' => sprintf(
                'Settlement #%s approved for period %s to %s',
                $settlement->reference_number,
                $settlement->period_start->format('d M Y'),
                $settlement->period_end->format('d M Y')
            ),
            'metadata' => [
                'settlement_id' => $settlement->id,
                'reference_number' => $settlement->reference_number,
                'approved_by' => $approvedBy->id,
            ],
        ]);

        return $settlement->fresh(['screenProvider', 'generatedByUser', 'approvedByUser']);
    }

    /**
     * Mark a settlement as paid.
     */
    public function markAsPaid(ScreenProviderSettlement $settlement, User $paidBy, ?string $referenceNumber = null): ScreenProviderSettlement
    {
        if (!$settlement->canBePaid()) {
            throw new \RuntimeException('Settlement cannot be paid in its current status: ' . $settlement->status);
        }

        $settlement->update([
            'status' => ScreenProviderSettlement::STATUS_PAID,
            'paid_by' => $paidBy->id,
            'paid_at' => now(),
            'notes' => $referenceNumber
                ? ($settlement->notes ? $settlement->notes . "\nPayment Ref: " . $referenceNumber : 'Payment Ref: ' . $referenceNumber)
                : $settlement->notes,
        ]);

        ScreenProviderActivity::create([
            'screen_provider_id' => $settlement->screen_provider_id,
            'type' => 'settlement_paid',
            'description' => sprintf(
                'Settlement #%s marked as paid for period %s to %s — Amount: ₹%s',
                $settlement->reference_number,
                $settlement->period_start->format('d M Y'),
                $settlement->period_end->format('d M Y'),
                number_format($settlement->provider_revenue, 2)
            ),
            'metadata' => [
                'settlement_id' => $settlement->id,
                'reference_number' => $settlement->reference_number,
                'paid_by' => $paidBy->id,
                'paid_amount' => (float) $settlement->provider_revenue,
            ],
        ]);

        return $settlement->fresh(['screenProvider', 'generatedByUser', 'approvedByUser', 'paidByUser']);
    }

    /**
     * Cancel a settlement and revert linked booking_screens to pending.
     */
    public function cancelSettlement(ScreenProviderSettlement $settlement, ?string $reason = null): ScreenProviderSettlement
    {
        if (!$settlement->canBeCancelled()) {
            throw new \RuntimeException('Settlement cannot be cancelled in its current status: ' . $settlement->status);
        }

        DB::transaction(function () use ($settlement, $reason) {
            // Revert linked booking_screens
            BookingScreen::where('settlement_id', $settlement->id)
                ->update([
                    'settlement_id' => null,
                    'settlement_status' => 'pending',
                    'settled_at' => null,
                ]);

            $settlement->update([
                'status' => ScreenProviderSettlement::STATUS_CANCELLED,
                'notes' => $reason
                    ? ($settlement->notes ? $settlement->notes . "\nCancelled: " . $reason : 'Cancelled: ' . $reason)
                    : $settlement->notes,
            ]);

            ScreenProviderActivity::create([
                'screen_provider_id' => $settlement->screen_provider_id,
                'type' => 'settlement_cancelled',
                'description' => sprintf(
                    'Settlement #%s cancelled for period %s to %s' . ($reason ? ' — Reason: ' . $reason : ''),
                    $settlement->reference_number,
                    $settlement->period_start->format('d M Y'),
                    $settlement->period_end->format('d M Y')
                ),
                'metadata' => [
                    'settlement_id' => $settlement->id,
                    'reference_number' => $settlement->reference_number,
                    'reason' => $reason,
                ],
            ]);
        });

        return $settlement->fresh(['screenProvider', 'generatedByUser']);
    }

    /**
     * Get settlements for a specific provider with optional status filter.
     */
    public function getProviderSettlements(int $screenProviderId, array $filters = [])
    {
        $perPage = $filters['per_page'] ?? 10;

        $query = ScreenProviderSettlement::where('screen_provider_id', $screenProviderId)
            ->with(['generatedByUser', 'approvedByUser', 'paidByUser']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get enhanced revenue stats for a screen provider including settlement data.
     */
    public function getProviderRevenueStatsWithSettlements(int $screenProviderId): array
    {
        $ownedScreensCount = Screen::where('screen_provider_id', $screenProviderId)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->count();

        $pendingSettlement = (float) BookingScreen::where('screen_provider_id', $screenProviderId)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->where('settlement_status', 'pending')
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                    Booking::STATUS_COMPLETED,
                ]);
            })
            ->sum('provider_share');

        $settledRevenue = (float) BookingScreen::where('screen_provider_id', $screenProviderId)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->where('settlement_status', 'settled')
            ->sum('provider_share');

        $totalGrossRevenue = (float) BookingScreen::where('screen_provider_id', $screenProviderId)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                    Booking::STATUS_COMPLETED,
                ]);
            })
            ->sum('gross_revenue');

        $totalProviderRevenue = $pendingSettlement + $settledRevenue;

        $campaignCount = BookingScreen::where('screen_provider_id', $screenProviderId)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                    Booking::STATUS_COMPLETED,
                ]);
            })
            ->distinct('booking_id')
            ->count('booking_id');

        $lastSettlement = ScreenProviderSettlement::where('screen_provider_id', $screenProviderId)
            ->whereIn('status', [ScreenProviderSettlement::STATUS_PAID, ScreenProviderSettlement::STATUS_APPROVED])
            ->latest('created_at')
            ->first();

        return [
            'owned_screens_count' => $ownedScreensCount,
            'campaign_count' => $campaignCount,
            'total_gross_revenue' => $totalGrossRevenue,
            'total_provider_revenue' => $totalProviderRevenue,
            'pending_settlement' => $pendingSettlement,
            'settled_revenue' => $settledRevenue,
            'outstanding_revenue' => $pendingSettlement,
            'last_settlement' => $lastSettlement ? [
                'id' => $lastSettlement->id,
                'reference_number' => $lastSettlement->reference_number,
                'provider_revenue' => (float) $lastSettlement->provider_revenue,
                'period_start' => $lastSettlement->period_start->format('Y-m-d'),
                'period_end' => $lastSettlement->period_end->format('Y-m-d'),
                'status' => $lastSettlement->status,
                'created_at' => $lastSettlement->created_at->format('Y-m-d H:i:s'),
            ] : null,
        ];
    }
}
