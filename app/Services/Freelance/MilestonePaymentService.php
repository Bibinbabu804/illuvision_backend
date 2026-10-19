<?php

namespace App\Services\Freelance;

use App\Domain\Payment\Support\Money;
use App\Models\FreelanceDeliverable;
use App\Models\FreelanceNotification;
use App\Models\FreelanceProject;
use App\Models\FreelanceProjectMilestone;
use App\Models\FreelancerWallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MilestonePaymentService
{
    public function initializeMilestonesForProject(
        FreelanceProject $project,
        ?float $advancePct = null,
        ?float $finalPct = null
    ): array {
        return $this->createMilestonesForProject($project, $advancePct, $finalPct);
    }

    public function fundProjectPayment(FreelanceProject $project, float|int|string $amount): void
    {
        $project->update([
            'held_amount' => $amount,
        ]);
        if (!$project->milestones()->exists()) {
            $this->createMilestonesForProject($project);
        }
    }

    public function submitMilestoneDeliverable(
        FreelanceProject $project,
        FreelanceProjectMilestone $milestone,
        array $data
    ): FreelanceDeliverable {
        $user = User::find($data['user_id'] ?? null) ?? $project->freelancerProfile->user;
        return $this->submitMilestoneWork($project, $milestone, $data, $user);
    }

    /**
     * Create default advance & final milestones for a project.
     *
     * @param FreelanceProject $project
     * @param float|null $advancePct
     * @param float|null $finalPct
     * @return array<FreelanceProjectMilestone>
     */
    public function createMilestonesForProject(
        FreelanceProject $project,
        ?float $advancePct = null,
        ?float $finalPct = null
    ): array {
        if ($project->milestones()->exists()) {
            return $project->milestones()->get()->all();
        }

        $advancePct = $advancePct ?? (float) ($project->milestone_advance_pct ?: 30.00);
        $finalPct = $finalPct ?? (float) ($project->milestone_final_pct ?: (100.00 - $advancePct));

        $grossTotalPaise = Money::toPaise($project->gross_amount);
        $netTotalPaise = Money::toPaise($project->freelancer_amount);

        // Milestone 1: Advance
        $m1GrossPaise = (int) round($grossTotalPaise * ($advancePct / 100.0));
        $m1NetPaise = (int) round($netTotalPaise * ($advancePct / 100.0));
        $m1FeePaise = $m1GrossPaise - $m1NetPaise;

        // Milestone 2: Final (Exact remaining to prevent any fractional paise loss)
        $m2GrossPaise = $grossTotalPaise - $m1GrossPaise;
        $m2NetPaise = $netTotalPaise - $m1NetPaise;
        $m2FeePaise = $m2GrossPaise - $m2NetPaise;

        $milestones = [];

        $milestones[] = $project->milestones()->create([
            'milestone_number' => 1,
            'title' => 'Milestone 1: Advance Scope & Initial Deliverable',
            'description' => 'Initial milestone covering project kickoff and first milestone deliverable submission.',
            'percentage' => $advancePct,
            'gross_amount' => Money::toRupees($m1GrossPaise),
            'freelancer_amount' => Money::toRupees($m1NetPaise),
            'platform_fee' => Money::toRupees($m1FeePaise),
            'status' => 'pending',
        ]);

        $milestones[] = $project->milestones()->create([
            'milestone_number' => 2,
            'title' => 'Milestone 2: Final Delivery & Project Completion',
            'description' => 'Final milestone covering project completion, final deliverables, and source files.',
            'percentage' => $finalPct,
            'gross_amount' => Money::toRupees($m2GrossPaise),
            'freelancer_amount' => Money::toRupees($m2NetPaise),
            'platform_fee' => Money::toRupees($m2FeePaise),
            'status' => 'pending',
        ]);

        $project->update([
            'milestone_advance_pct' => $advancePct,
            'milestone_final_pct' => $finalPct,
            'held_amount' => $project->freelancer_amount,
            'released_amount' => 0.00,
        ]);

        return $milestones;
    }

    /**
     * Submit work for a specific milestone.
     */
    public function submitMilestoneWork(
        FreelanceProject $project,
        FreelanceProjectMilestone $milestone,
        array $data,
        User $submitter
    ): FreelanceDeliverable {
        abort_unless($milestone->project_id === $project->id, 422, 'Milestone does not belong to this project.');
        abort_unless(in_array($milestone->status, ['pending', 'in_progress', 'revision_requested'], true), 422, 'Milestone is not in a submittable status.');

        return DB::transaction(function () use ($project, $milestone, $data, $submitter) {
            $isFinal = ($milestone->milestone_number === 2 || $milestone->percentage >= 70.0);

            $deliverable = $project->deliverables()->create([
                'milestone_id' => $milestone->id,
                'submitted_by' => $submitter->id,
                'title' => $data['title'] ?? "Delivery for {$milestone->title}",
                'message' => $data['message'] ?? null,
                'revision_number' => $project->revision_count + 1,
                'is_final' => $isFinal,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            $milestone->update([
                'status' => 'submitted',
                'deliverable_id' => $deliverable->id,
                'submitted_at' => now(),
            ]);

            if ($project->status !== 'submitted') {
                $project->update(['status' => 'submitted']);
            }

            FreelanceNotification::create([
                'user_id' => $project->client_id,
                'type' => 'deliverable',
                'title' => "Milestone {$milestone->milestone_number} Submitted",
                'body' => "Work has been submitted for {$milestone->title}. Please review and approve to release funds.",
                'data' => ['project_id' => $project->id, 'milestone_id' => $milestone->id, 'deliverable_id' => $deliverable->id],
            ]);

            return $deliverable;
        });
    }

    /**
     * Approve milestone and release only this milestone's eligible portion to the freelancer's wallet.
     */
    public function approveMilestone(
        FreelanceProject $project,
        FreelanceProjectMilestone $milestone,
        User $client
    ): array {
        abort_unless($project->client_id === $client->id, 403, 'Only the client can approve this milestone.');
        abort_unless($milestone->project_id === $project->id, 422, 'Milestone does not belong to this project.');
        abort_unless(in_array($milestone->status, ['submitted', 'pending'], true), 422, 'Milestone cannot be approved at this stage.');

        return DB::transaction(function () use ($project, $milestone) {
            $project = FreelanceProject::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $milestone = FreelanceProjectMilestone::whereKey($milestone->id)->lockForUpdate()->firstOrFail();

            if ($milestone->status === 'released') {
                return ['project' => $project, 'milestone' => $milestone, 'wallet' => null];
            }

            // Verify held payment exists or project is in funded state
            $heldPayment = $project->payments()->whereIn('status', ['held', 'released'])->lockForUpdate()->latest()->first();
            if (!$heldPayment && (float) $project->held_amount <= 0 && $project->status !== 'in_progress') {
                abort(422, 'No verified funded payment found for this project.');
            }

            $idempotencyKey = 'MS-REL-' . $milestone->id . '-' . Str::random(12);

            // Update milestone
            $milestone->update([
                'status' => 'approved',
                'approved_at' => now(),
                'released_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);

            if ($milestone->deliverable) {
                $milestone->deliverable()->update(['status' => 'approved']);
            }

            // Release milestone net earnings to Freelancer Wallet
            $wallet = FreelancerWallet::firstOrCreate(['freelancer_profile_id' => $project->freelancer_profile_id]);
            $wallet->refresh();

            $currentBalPaise = Money::toPaise($wallet->available_balance);
            $milestoneReleasePaise = Money::toPaise($milestone->freelancer_amount);
            $lifetimePaise = Money::toPaise($wallet->lifetime_earnings);

            $newBalPaise = $currentBalPaise + $milestoneReleasePaise;
            $newLifetimePaise = $lifetimePaise + $milestoneReleasePaise;

            $wallet->available_balance = Money::toRupees($newBalPaise);
            $wallet->lifetime_earnings = Money::toRupees($newLifetimePaise);
            $wallet->save();

            // Record immutable ledger entry
            $wallet->transactions()->create([
                'project_id' => $project->id,
                'type' => 'milestone_release',
                'amount' => $milestone->freelancer_amount,
                'balance_after' => $wallet->available_balance,
                'reference' => 'WAL-MS-' . $milestone->id . '-' . strtoupper(Str::random(8)),
                'description' => "Milestone {$milestone->milestone_number} ({$milestone->percentage}%) payment released: {$milestone->title}",
                'metadata' => [
                    'milestone_id' => $milestone->id,
                    'milestone_number' => $milestone->milestone_number,
                    'percentage' => $milestone->percentage,
                    'gross_amount' => $milestone->gross_amount,
                    'freelancer_amount' => $milestone->freelancer_amount,
                ],
            ]);

            // Update project held and released balances
            $newReleasedPaise = Money::toPaise($project->released_amount) + $milestoneReleasePaise;
            $totalNetPaise = Money::toPaise($project->freelancer_amount);
            $newHeldPaise = max(0, $totalNetPaise - $newReleasedPaise);

            $project->released_amount = Money::toRupees($newReleasedPaise);
            $project->held_amount = Money::toRupees($newHeldPaise);

            // Determine if all milestones are released or if this is the final milestone
            $allReleased = ($project->milestones()->whereNotIn('status', ['released', 'approved'])->count() === 0);

            if ($allReleased || $milestone->milestone_number === 2) {
                $project->status = 'completed';
                $project->completed_at = now();
                if ($heldPayment) {
                    $heldPayment->update(['status' => 'released', 'released_at' => now()]);
                }
            } else {
                // Advance milestone approved: project remains in_progress for next milestone
                $project->status = 'in_progress';
            }

            $project->save();

            FreelanceNotification::create([
                'user_id' => $project->freelancerProfile->user_id,
                'type' => 'payment_released',
                'title' => "Milestone {$milestone->milestone_number} Approved & Released",
                'body' => "₹" . number_format($milestone->freelancer_amount, 2) . " has been credited to your wallet for {$milestone->title}.",
                'data' => [
                    'project_id' => $project->id,
                    'milestone_id' => $milestone->id,
                    'amount' => $milestone->freelancer_amount,
                ],
            ]);

            return [
                'project' => $project->fresh(['milestones', 'deliverables']),
                'milestone' => $milestone->fresh(),
                'wallet' => $wallet->fresh(),
            ];
        });
    }

    /**
     * Request revision on a milestone deliverable.
     */
    public function requestMilestoneRevision(
        FreelanceProject $project,
        FreelanceProjectMilestone $milestone,
        string $reason,
        User $client
    ): FreelanceProjectMilestone {
        abort_unless($project->client_id === $client->id, 403);
        abort_unless($milestone->project_id === $project->id, 422);
        abort_unless($milestone->status === 'submitted', 422, 'Milestone must be in submitted state to request revision.');

        return DB::transaction(function () use ($project, $milestone, $reason, $client) {
            $milestone->update(['status' => 'revision_requested']);

            if ($milestone->deliverable) {
                $milestone->deliverable()->update(['status' => 'revision_requested']);
                $milestone->deliverable->comments()->create([
                    'user_id' => $client->id,
                    'comment' => "Revision requested: {$reason}",
                ]);
            }

            $project->update([
                'status' => 'revision_requested',
                'revision_count' => DB::raw('revision_count + 1'),
            ]);

            FreelanceNotification::create([
                'user_id' => $project->freelancerProfile->user_id,
                'type' => 'revision_requested',
                'title' => "Revision Requested on Milestone {$milestone->milestone_number}",
                'body' => "Client requested changes for {$milestone->title}: {$reason}",
                'data' => ['project_id' => $project->id, 'milestone_id' => $milestone->id],
            ]);

            return $milestone->fresh();
        });
    }
}
