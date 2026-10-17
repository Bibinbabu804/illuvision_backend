<?php

namespace App\Infrastructure\Financial\Persistence;

use App\Domain\Financial\Contracts\PartnerCommissionRepositoryInterface;
use App\Models\PartnerCommission;

/**
 * EloquentPartnerCommissionRepository
 *
 * Infrastructure adapter for partner commission persistence.
 *
 * `findOrFailForUpdate()` preserves the legacy `->lockForUpdate()->firstOrFail()`
 * call exactly, so the pessimistic lock and its position inside the caller's
 * transaction are unchanged.
 */
class EloquentPartnerCommissionRepository implements PartnerCommissionRepositoryInterface
{
    public function find(int $commissionId): ?PartnerCommission
    {
        return PartnerCommission::where('id', $commissionId)->first();
    }

    public function findOrFail(int $commissionId): PartnerCommission
    {
        return PartnerCommission::where('id', $commissionId)->firstOrFail();
    }

    public function findOrFailForUpdate(int $commissionId): PartnerCommission
    {
        return PartnerCommission::where('id', $commissionId)->lockForUpdate()->firstOrFail();
    }

    public function findForReferralUsage(int $referralUsageId): ?PartnerCommission
    {
        return PartnerCommission::where('referral_usage_id', $referralUsageId)->first();
    }

    public function create(array $attributes): PartnerCommission
    {
        return PartnerCommission::create($attributes);
    }
}
