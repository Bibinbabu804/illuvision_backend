<?php

namespace App\Application\Financial\UseCases;

use App\Models\User;
use App\Services\SettlementService;

/**
 * GenerateProviderSettlementUseCase
 *
 * Application entry point for turning settled-eligible immutable revenue
 * snapshots into a payable settlement instruction for one screen provider.
 *
 * WHY THIS IS THE BOUNDARY (and why approve/paid/cancel are not):
 * Settlement *generation* is the only settlement operation that performs
 * orchestration — eligibility retrieval, snapshot summation, reference-number
 * minting, transactional fan-out across booking_screens and an activity record.
 * It therefore earns an application use case. The remaining lifecycle
 * transitions (`approveSettlement`, `markAsPaid`, `cancelSettlement`) are thin,
 * already-correctly-owned domain behaviour on `SettlementService` with no
 * orchestration to lift; wrapping each in a one-line delegating class would add
 * indirection without adding a boundary (Architecture Rule 7 and the
 * "no speculative abstractions" constraint).
 *
 * BEHAVIOURAL CONTRACT (unchanged — delegated wholesale):
 *  - Eligibility and amounts come exclusively from `booking_screens` snapshots
 *    (gross_revenue / platform_share / provider_share). Live provider
 *    percentages and live screen ownership are never consulted.
 *  - Throws \RuntimeException with a period- or booking-specific message when no
 *    eligible rows exist — the HTTP layer maps this to 422 and the message text
 *    is part of the observable contract.
 *  - Inside its transaction: settlement row insert, per-row
 *    settlement_id/settlement_status/settled_at fan-out, activity log.
 *  - No settlement is ever recalculated from live pricing afterwards.
 */
class GenerateProviderSettlementUseCase
{
    public function __construct(
        protected SettlementService $settlementService
    ) {}

    /**
     * @param  string  $periodStart  Y-m-d
     * @param  string  $periodEnd    Y-m-d
     * @param  int|null  $bookingId  When supplied, restrict to a single booking.
     * @throws \RuntimeException When no eligible booking screens exist.
     */
    public function execute(
        int $screenProviderId,
        string $periodStart,
        string $periodEnd,
        User $generatedBy,
        ?int $bookingId = null
    ) {
        return $this->settlementService->generateSettlement(
            $screenProviderId,
            $periodStart,
            $periodEnd,
            $generatedBy,
            $bookingId
        );
    }
}
