<?php

namespace App\Domain\Financial\Contracts;

use App\Models\PartnerCommission;

/**
 * PartnerCommissionRepositoryInterface
 *
 * Persistence boundary for partner commissions (Phase 0.10).
 *
 * JUSTIFICATION (Architecture Rule 6):
 * Partner commission is a payable financial liability. Both the attribution
 * write path (ReferralTrackingService) and the admin lifecycle transitions
 * (CommissionService) mutate it, and the lifecycle transitions use
 * `lockForUpdate()`. Isolating the retrieval-with-pessimistic-lock behind a
 * contract makes the lock an explicit, reviewable property of the boundary
 * rather than an incidental detail of an application service.
 *
 * LOCKING FIDELITY (critical):
 * `lockForUpdate()` is a genuine pessimistic row lock (SELECT ... FOR UPDATE).
 * Implementations MUST NOT silently drop it, move it outside the surrounding
 * transaction, or change the lock acquisition order. `PartnerCommission::create()`
 * on the attribution path deliberately takes NO lock — that asymmetry is
 * preserved, not normalised.
 *
 * Only `lockForUpdate()` and `firstOrFail()` semantics count as pessimistic
 * locking; `first()`/`find()`/`findOrFail()` do not.
 */
interface PartnerCommissionRepositoryInterface
{
    /**
     * Retrieve a commission by primary key with NO row lock.
     */
    public function find(int $commissionId): ?PartnerCommission;

    /**
     * Retrieve a commission by primary key or fail. No row lock.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFail(int $commissionId): PartnerCommission;

    /**
     * Retrieve a commission by primary key holding a pessimistic row lock
     * (SELECT ... FOR UPDATE) for the remainder of the current transaction.
     *
     * The caller is responsible for being inside a transaction; this method
     * does not open one.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOrFailForUpdate(int $commissionId): PartnerCommission;

    /**
     * Retrieve the first commission generated for a referral usage (no lock).
     */
    public function findForReferralUsage(int $referralUsageId): ?PartnerCommission;

    /**
     * Persist a new commission row (no row lock, matching legacy behaviour).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PartnerCommission;
}
