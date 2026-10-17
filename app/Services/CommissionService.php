<?php

namespace App\Services;

use App\Domain\Financial\Contracts\PartnerCommissionRepositoryInterface;
use App\Support\Events\Notifications\CommissionGenerated;
use App\Models\PartnerCommission;
use App\Models\ReferralUsage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CommissionService
 *
 * Owns the partner commission LIFECYCLE (settlement of a payable liability):
 * approve -> paid / cancelled, plus reporting reads.
 *
 * PHASE 0.10 STRUCTURAL CHANGE (behaviour-preserving):
 * The three `PartnerCommission::where('id', ...)->lockForUpdate()->firstOrFail()`
 * lookups moved into `PartnerCommissionRepositoryInterface::findOrFailForUpdate()`.
 * The pessimistic row lock (SELECT ... FOR UPDATE), its position inside the
 * surrounding transaction, and the lock ordering (commission row first, then the
 * referral_usage row via the relationship) are all unchanged. No lock was added,
 * removed or reordered.
 *
 * `getCommission()` intentionally uses the NON-locking `findOrFail()`: it is a
 * read endpoint and adding a lock would be a behaviour change.
 */
class CommissionService
{
    public function __construct(
        protected PartnerCommissionRepositoryInterface $commissionRepository,
    ) {}

    /**
     * Approve a pending commission.
     */
    public function approve(int $commissionId, int $approvedBy, ?string $notes = null): PartnerCommission
    {
        return DB::transaction(function () use ($commissionId, $approvedBy, $notes) {
            $commission = $this->commissionRepository->findOrFailForUpdate($commissionId);

            if ($commission->status !== PartnerCommission::STATUS_PENDING) {
                throw new InvalidArgumentException("Cannot approve commission in '{$commission->status}' status.");
            }

            $commission->update([
                'status' => PartnerCommission::STATUS_APPROVED,
                'approved_by' => $approvedBy,
                'approved_at' => now(),
                'notes' => $notes ? ($commission->notes ? $commission->notes . "\n---\nApproval note: " . $notes : 'Approval note: ' . $notes) : $commission->notes,
            ]);

            return $commission->fresh();
        });
    }

    /**
     * Mark a commission as paid.
     */
    public function markAsPaid(int $commissionId, ?string $notes = null, ?array $payoutData = null): PartnerCommission
    {
        return DB::transaction(function () use ($commissionId, $notes, $payoutData) {
            $commission = $this->commissionRepository->findOrFailForUpdate($commissionId);

            if ($commission->status === PartnerCommission::STATUS_PAID) {
                return $commission;
            }

            if (in_array($commission->status, [PartnerCommission::STATUS_CANCELLED, PartnerCommission::STATUS_REFUNDED], true)) {
                throw new InvalidArgumentException("Cannot mark a {$commission->status} commission as paid.");
            }

            $updateData = [
                'status' => PartnerCommission::STATUS_PAID,
                'paid_at' => now(),
                'notes' => $notes ? ($commission->notes ? $commission->notes . "\n---\n" . $notes : $notes) : $commission->notes,
            ];

            if ($payoutData) {
                if (isset($payoutData['payout_date'])) {
                    $updateData['payout_date'] = $payoutData['payout_date'];
                }
                if (isset($payoutData['payout_reference'])) {
                    $updateData['payout_reference'] = $payoutData['payout_reference'];
                }
                if (isset($payoutData['payout_method'])) {
                    $updateData['payout_method'] = $payoutData['payout_method'];
                }
                if (isset($payoutData['payout_notes'])) {
                    $updateData['payout_notes'] = $payoutData['payout_notes'];
                }
            }

            $commission->update($updateData);

            // Update the referral usage status to paid
            if ($commission->referralUsage) {
                $commission->referralUsage->update(['status' => 'paid']);
            }

            return $commission->fresh();
        });
    }

    /**
     * Mark a commission as cancelled.
     */
    public function markAsCancelled(int $commissionId, ?string $notes = null): PartnerCommission
    {
        return DB::transaction(function () use ($commissionId, $notes) {
            $commission = $this->commissionRepository->findOrFailForUpdate($commissionId);

            if ($commission->status === PartnerCommission::STATUS_PAID) {
                throw new InvalidArgumentException("Cannot cancel an already paid commission directly.");
            }

            $commission->update([
                'status' => PartnerCommission::STATUS_CANCELLED,
                'notes' => $notes ? ($commission->notes ? $commission->notes . "\n---\n" . $notes : $notes) : $commission->notes,
            ]);

            if ($commission->referralUsage) {
                $commission->referralUsage->update(['status' => 'cancelled']);
            }

            return $commission->fresh();
        });
    }

    /**
     * Get a single commission with full relations.
     */
    public function getCommission(int $commissionId): PartnerCommission
    {
        return $this->commissionRepository->findOrFail($commissionId)->load([
            'partner',
            'referralUsage.referralCode',
            'referralUsage.booking.user',
            'referralUsage.booking.screen',
            'referralUsage.payment',
        ]);
    }

    /**
     * Get all commissions with partner info, paginated.
     */
    public function getAllCommissions(array $filters = [])
    {
        $query = PartnerCommission::with([
            'partner',
            'referralUsage.referralCode',
        ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('partner', function ($pq) use ($search) {
                    $pq->where('name', 'like', "%{$search}%");
                })->orWhereHas('referralUsage.referralCode', function ($rq) use ($search) {
                    $rq->where('code', 'like', "%{$search}%");
                });
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get commission summary across all partners.
     */
    public function getSummary(): array
    {
        return [
            'total_earned' => (float) PartnerCommission::sum('amount'),
            'pending' => (float) PartnerCommission::where('status', 'pending')->sum('amount'),
            'approved' => (float) PartnerCommission::where('status', 'approved')->sum('amount'),
            'paid' => (float) PartnerCommission::where('status', 'paid')->sum('amount'),
            'cancelled' => (float) PartnerCommission::where('status', 'cancelled')->sum('amount'),
            'total_commissions' => PartnerCommission::count(),
            'pending_count' => PartnerCommission::where('status', 'pending')->count(),
            'paid_count' => PartnerCommission::where('status', 'paid')->count(),
        ];
    }

    /**
     * Get commission status timeline for a specific commission.
     */
    public function getStatusTimeline(PartnerCommission $commission): array
    {
        $events = [];

        // Created
        $events[] = [
            'type' => 'created',
            'label' => 'Commission Generated',
            'date' => $commission->generated_at?->format('Y-m-d H:i:s') ?? $commission->created_at->format('Y-m-d H:i:s'),
        ];

        // Approved
        if ($commission->approved_at) {
            $events[] = [
                'type' => 'approved',
                'label' => 'Commission Approved',
                'date' => $commission->approved_at->format('Y-m-d H:i:s'),
            ];
        }

        // Paid
        if ($commission->paid_at) {
            $events[] = [
                'type' => 'paid',
                'label' => 'Commission Paid',
                'date' => $commission->paid_at->format('Y-m-d H:i:s'),
            ];
        }

        // Cancelled
        if ($commission->status === 'cancelled') {
            $events[] = [
                'type' => 'cancelled',
                'label' => 'Commission Cancelled',
                'date' => $commission->updated_at->format('Y-m-d H:i:s'),
            ];
        }

        return $events;
    }
}
