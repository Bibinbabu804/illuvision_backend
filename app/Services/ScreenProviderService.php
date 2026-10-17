<?php

namespace App\Services;
use App\Support\Exceptions\UserConflictException;
use App\Support\Events\Notifications\ScreenProviderApproved;
use App\Support\Events\Notifications\ScreenProviderCreated;
use App\Support\Events\Notifications\ScreenProviderRejected;
use App\Support\Events\Notifications\ScreenProviderPortalInvitation;
use App\Models\ActivityLog;
use App\Models\Screen;
use App\Models\ScreenProvider;
use App\Models\ScreenProviderActivity;
use App\Models\ScreenProviderEnquiry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScreenProviderService
{
    /**
     * Approve an enquiry and create a Screen Provider from it.
     */
    public function approveFromEnquiry(ScreenProviderEnquiry $enquiry, User $admin, bool $linkExisting = false): ScreenProvider
    {
        // Check for existing provider with same email
        $existing = ScreenProvider::where('email', $enquiry->email)->first();
        if ($existing) {
            throw new \RuntimeException('A screen provider with this email already exists: ' . $existing->provider_name);
        }

        // Check for existing user account using provider email
        $existingUser = User::where('email', $enquiry->email)->first();
        if ($existingUser && !$linkExisting) {
            throw new UserConflictException($existingUser);
        }

        return DB::transaction(function () use ($enquiry, $admin, $existingUser) {
            // Create the screen provider record from enquiry data
            $screenProvider = ScreenProvider::create([
                'provider_name' => $enquiry->name,
                'business_name' => null,
                'contact_person' => $enquiry->name,
                'phone' => $enquiry->phone,
                'email' => $enquiry->email,
                'business_type' => ScreenProvider::BUSINESS_TYPE_INDIVIDUAL,
                'status' => ScreenProvider::STATUS_APPROVED,
                'notes' => null,
                'approved_at' => now(),
                'approved_by' => $admin->id,
                'created_from_enquiry' => true,
                'screen_provider_enquiry_id' => $enquiry->id,
                'created_by' => $admin->id,
            ]);

            // Create or link User account and assign role
            if ($existingUser) {
                $user = $existingUser;
                $user->update(['role' => 'screen_provider']);
            } else {
                $password = Str::random(16);
                $user = User::create([
                    'name' => $enquiry->name,
                    'email' => $enquiry->email,
                    'password' => \Illuminate\Support\Facades\Hash::make($password),
                    'role' => 'screen_provider',
                    'phone' => $enquiry->phone,
                    'status' => 'active',
                ]);
            }

            // Associate the User with the Screen Provider
            $screenProvider->update([
                'user_id' => $user->id,
            ]);

            // Update the enquiry status to 'converted' and link the provider
            $enquiry->update([
                'status' => 'converted',
                'screen_provider_id' => $screenProvider->id,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);

            // Log activity: provider created from enquiry
            $this->addActivity(
                $screenProvider,
                'created',
                'Screen provider created from enquiry #' . $enquiry->id,
                $admin,
                ['enquiry_id' => $enquiry->id, 'source' => 'enquiry_approval']
            );

            // Log activity: application linked
            $this->addActivity(
                $screenProvider,
                'application_linked',
                'Linked to enquiry submitted by ' . $enquiry->name,
                $admin,
                ['enquiry_id' => $enquiry->id]
            );

            // Log activity: approved
            $this->addActivity(
                $screenProvider,
                'approved',
                'Screen provider approved by ' . $admin->name,
                $admin,
                ['approved_by' => $admin->id]
            );

            // Log global activity
            ActivityLog::log(
                'screen_provider_approved',
                'screen_provider',
                $screenProvider->id,
                "Screen provider \"{$screenProvider->provider_name}\" approved by {$admin->name}"
            );

            // Generate setup token for password setup (using password_setup_tokens table)
            $setupToken = $this->generatePasswordSetupToken($screenProvider->email);

            // Dispatch events
            ScreenProviderCreated::dispatch($screenProvider);
            ScreenProviderApproved::dispatch($screenProvider);
            ScreenProviderPortalInvitation::dispatch($screenProvider, $setupToken);

            return $screenProvider->fresh();
        });
    }

    /**
     * Send portal invitation (creates/links user, generates setup token, dispatches approved event).
     */
    public function sendPortalInvitation(ScreenProvider $screenProvider, User $admin, bool $linkExisting = false): array
    {
        if (!$screenProvider->isApproved()) {
            throw new \RuntimeException('Only approved screen providers can be invited to the portal.');
        }

        // Check for existing user account using provider email
        $existingUser = User::where('email', $screenProvider->email)->first();
        if ($existingUser && !$linkExisting && $screenProvider->user_id !== $existingUser->id) {
            throw new UserConflictException($existingUser);
        }

        return DB::transaction(function () use ($screenProvider, $admin, $existingUser) {
            // Create or Link User account and assign role
            if ($existingUser) {
                $user = $existingUser;
                $user->update(['role' => 'screen_provider']);
            } else {
                $password = Str::random(16);
                $user = User::create([
                    'name' => $screenProvider->contact_person ?: $screenProvider->provider_name,
                    'email' => $screenProvider->email,
                    'password' => \Illuminate\Support\Facades\Hash::make($password),
                    'role' => 'screen_provider',
                    'phone' => $screenProvider->phone,
                    'status' => 'active',
                ]);
            }

            // Associate user_id on Screen Provider record
            $screenProvider->update([
                'user_id' => $user->id,
            ]);

            // Generate setup token for password setup
            $setupToken = $this->generatePasswordSetupToken($screenProvider->email);

            // Log activity
            $this->addActivity(
                $screenProvider,
                'portal_invitation_sent',
                'Portal invitation sent to ' . $screenProvider->email . ' by ' . $admin->name,
                $admin,
                ['user_id' => $user->id]
            );

            // Log global activity
            ActivityLog::log(
                'screen_provider_portal_invitation_sent',
                'screen_provider',
                $screenProvider->id,
                "Portal invitation sent to screen provider \"{$screenProvider->provider_name}\" by {$admin->name}"
            );

            // Dispatch event (triggers and queues notification email)
            ScreenProviderPortalInvitation::dispatch($screenProvider, $setupToken);

            return [
                'screen_provider' => $screenProvider->fresh(),
                'user' => $user,
                'setup_token' => $setupToken,
            ];
        });
    }

    /**
     * Reject an enquiry with a reason.
     */
    public function rejectEnquiry(ScreenProviderEnquiry $enquiry, User $admin, ?string $reason = null): ScreenProviderEnquiry
    {
        $enquiry->update([
            'status' => 'closed',
            'internal_notes' => $reason
                ? ($enquiry->internal_notes ? $enquiry->internal_notes . "\n---\nRejection reason: " . $reason : 'Rejection reason: ' . $reason)
                : $enquiry->internal_notes,
        ]);

        return $enquiry->fresh();
    }

    /**
     * Manually create a Screen Provider (without enquiry).
     */
    public function create(array $data, User $admin): ScreenProvider
    {
        return DB::transaction(function () use ($data, $admin) {
            $screenProvider = ScreenProvider::create([
                'provider_name' => $data['provider_name'],
                'business_name' => $data['business_name'] ?? null,
                'contact_person' => $data['contact_person'] ?? $data['provider_name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'business_type' => $data['business_type'] ?? ScreenProvider::BUSINESS_TYPE_INDIVIDUAL,
                'status' => $data['status'] ?? ScreenProvider::STATUS_PENDING_REVIEW,
                'notes' => $data['notes'] ?? null,
                'illuvision_percentage' => $data['illuvision_percentage'] ?? 10.00,
                'screen_provider_percentage' => $data['screen_provider_percentage'] ?? 90.00,
                'created_by' => $admin->id,
            ]);

            // If status is approved on creation, set approval fields
            if ($screenProvider->isApproved()) {
                $screenProvider->update([
                    'approved_at' => now(),
                    'approved_by' => $admin->id,
                ]);

                $this->addActivity(
                    $screenProvider,
                    'approved',
                    'Screen provider approved by ' . $admin->name,
                    $admin
                );

                ScreenProviderApproved::dispatch($screenProvider);
            }

            // Log activity: created
            $this->addActivity(
                $screenProvider,
                'created',
                'Screen provider created by ' . $admin->name,
                $admin,
                ['source' => 'manual_creation']
            );

            ScreenProviderCreated::dispatch($screenProvider);

            return $screenProvider->fresh();
        });
    }

    /**
     * Update a Screen Provider.
     */
    public function update(ScreenProvider $screenProvider, array $data, User $admin): ScreenProvider
    {
        return DB::transaction(function () use ($screenProvider, $data, $admin) {
            $oldStatus = $screenProvider->status;
            $oldIlluvision = $screenProvider->illuvision_percentage;
            $oldProvider = $screenProvider->screen_provider_percentage;

            $screenProvider->update($data);

            $changes = $screenProvider->getChanges();

            // Track status changes
            if (array_key_exists('status', $changes)) {
                $newStatus = $screenProvider->status;

                if ($newStatus === ScreenProvider::STATUS_APPROVED) {
                    $screenProvider->update([
                        'approved_at' => now(),
                        'approved_by' => $admin->id,
                    ]);
                    $this->addActivity($screenProvider, 'approved', 'Screen provider approved by ' . $admin->name, $admin);
                    ScreenProviderApproved::dispatch($screenProvider);
                } elseif ($newStatus === ScreenProvider::STATUS_REJECTED) {
                    $reason = $data['notes'] ?? null;
                    $this->addActivity($screenProvider, 'rejected', 'Screen provider rejected by ' . $admin->name . ($reason ? ': ' . $reason : ''), $admin, ['reason' => $reason]);
                    ScreenProviderRejected::dispatch($screenProvider, $reason);
                } else {
                    $statusLabels = ScreenProvider::statuses();
                    $oldLabel = $statusLabels[$oldStatus] ?? $oldStatus;
                    $newLabel = $statusLabels[$newStatus] ?? $newStatus;
                    $this->addActivity($screenProvider, 'updated', "Status changed from \"{$oldLabel}\" to \"{$newLabel}\"", $admin, ['old_status' => $oldStatus, 'new_status' => $newStatus]);
                }
            }

            // Track split changes
            if (array_key_exists('illuvision_percentage', $changes) || array_key_exists('screen_provider_percentage', $changes)) {
                $newIlluvision = $screenProvider->illuvision_percentage;
                $newProvider = $screenProvider->screen_provider_percentage;
                $this->addActivity(
                    $screenProvider,
                    'revenue_split_updated',
                    "Revenue Split Changed: Illuvision {$oldIlluvision}% → {$newIlluvision}%, Provider {$oldProvider}% → {$newProvider}%",
                    $admin,
                    [
                        'old_illuvision_percentage' => $oldIlluvision,
                        'new_illuvision_percentage' => $newIlluvision,
                        'old_provider_percentage' => $oldProvider,
                        'new_provider_percentage' => $newProvider,
                    ]
                );
            }

            // Track other profile changes
            $profileFields = ['provider_name', 'business_name', 'contact_person', 'phone', 'email', 'business_type', 'notes'];
            $profileChanged = false;
            foreach ($profileFields as $field) {
                if (array_key_exists($field, $changes)) {
                    $profileChanged = true;
                    break;
                }
            }

            if ($profileChanged) {
                $this->addActivity($screenProvider, 'updated', 'Profile Updated', $admin);
            }

            return $screenProvider->fresh();
        });
    }

    /**
     * Update revenue split percentages for a screen provider.
     */
    public function updateRevenueSplit(ScreenProvider $screenProvider, float $illuvisionPct, float $providerPct, User $admin): ScreenProvider
    {
        return DB::transaction(function () use ($screenProvider, $illuvisionPct, $providerPct, $admin) {
            $oldIlluvision = $screenProvider->illuvision_percentage;
            $oldProvider = $screenProvider->screen_provider_percentage;

            $screenProvider->update([
                'illuvision_percentage' => $illuvisionPct,
                'screen_provider_percentage' => $providerPct,
            ]);

            $this->addActivity(
                $screenProvider,
                'revenue_split_updated',
                "Revenue split updated: Illuvision {$oldIlluvision}% → {$illuvisionPct}%, Provider {$oldProvider}% → {$providerPct}%",
                $admin,
                [
                    'old_illuvision_percentage' => $oldIlluvision,
                    'new_illuvision_percentage' => $illuvisionPct,
                    'old_provider_percentage' => $oldProvider,
                    'new_provider_percentage' => $providerPct,
                ]
            );

            return $screenProvider->fresh();
        });
    }

    /**
     * Log a screen assignment or ownership change.
     */
    public function logScreenOwnershipChange(Screen $screen, ?User $admin, ?array $oldValues = null): void
    {
        $ownerLabel = $screen->isOwnedByIlluvision()
            ? 'Illuvision'
            : ($screen->screenProvider?->provider_name ?? 'Unknown Provider');

        ScreenProviderActivity::create([
            'screen_provider_id' => $screen->screen_provider_id,
            'type' => 'screen_assigned',
            'description' => "Screen \"{$screen->name}\" ({$screen->screen_code}) assigned to {$ownerLabel}",
            'performed_by' => $admin?->id,
            'metadata' => [
                'screen_id' => $screen->id,
                'screen_name' => $screen->name,
                'screen_code' => $screen->screen_code,
                'owner_type' => $screen->owner_type,
                'screen_provider_id' => $screen->screen_provider_id,
                'old_values' => $oldValues,
            ],
        ]);

        // Also log to global activity log
        ActivityLog::log(
            'screen_ownership_changed',
            'screen',
            $screen->id,
            "Screen \"{$screen->name}\" ownership set to {$ownerLabel}",
            $oldValues,
            [
                'owner_type' => $screen->owner_type,
                'screen_provider_id' => $screen->screen_provider_id,
            ]
        );
    }

    /**
     * Add an activity record to the timeline.
     */
    public function addActivity(
        ScreenProvider $screenProvider,
        string $type,
        string $description,
        ?User $performedBy = null,
        ?array $metadata = null
    ): ScreenProviderActivity {
        return ScreenProviderActivity::create([
            'screen_provider_id' => $screenProvider->id,
            'type' => $type,
            'description' => $description,
            'performed_by' => $performedBy?->id,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Add a note activity with description.
     */
    public function addNote(ScreenProvider $screenProvider, string $note, User $admin): ScreenProviderActivity
    {
        return $this->addActivity(
            $screenProvider,
            'note_added',
            $note,
            $admin
        );
    }

    /**
     * Get activity timeline for a screen provider.
     */
    public function getActivityTimeline(ScreenProvider $screenProvider, int $limit = 50): array
    {
        return $screenProvider->activities()
            ->with('performedByUser')
            ->orderBy('created_at', 'desc')
            ->take($limit)
            ->get()
            ->toArray();
    }

    /**
     * Generate a new password setup token and invalidate old unused ones.
     */
    public function generatePasswordSetupToken(string $email): string
    {
        // Invalidate any previous unused setup tokens for this email
        DB::table('password_setup_tokens')
            ->where('email', $email)
            ->where('used', false)
            ->update([
                'used' => true,
                'expires_at' => now(), // expire it immediately
            ]);

        $token = Str::random(64);
        DB::table('password_setup_tokens')->insert([
            'email' => $email,
            'token' => $token,
            'expires_at' => now()->addDays(7),
            'used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
