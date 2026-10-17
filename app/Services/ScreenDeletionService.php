<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\Screen;
use App\Models\ScreenProvider;
use App\Models\ScreenProviderActivity;
use Illuminate\Support\Facades\DB;

class ScreenDeletionService
{
    /**
     * Booking statuses considered active or scheduled for future campaign execution.
     * Deletion of screens associated with these statuses (and end_time >= NOW()) is BLOCKED.
     */
    const ACTIVE_STATUSES = [
        Booking::STATUS_PAYMENT_PENDING,
        Booking::STATUS_MEDIA_REVIEW_PENDING,
        Booking::STATUS_PENDING_APPROVAL,
        Booking::STATUS_APPROVED,
        Booking::STATUS_SCHEDULED,
        Booking::STATUS_RUNNING,
    ];

    /**
     * Inspect candidate screen IDs against booking items & bookings to identify active/future campaigns.
     */
    public function validateDeletion(array $screenIds): array
    {
        $uniqueIds = array_values(array_unique(array_filter($screenIds, fn($id) => is_numeric($id) && $id > 0)));

        if (empty($uniqueIds)) {
            return [
                'total' => 0,
                'safe_count' => 0,
                'blocked_count' => 0,
                'safe_screen_ids' => [],
                'blocked' => [],
            ];
        }

        // Find active/future bookings referencing any of these screens
        $activeBookings = BookingScreen::query()
            ->join('bookings', 'booking_screens.booking_id', '=', 'bookings.id')
            ->join('screens', 'booking_screens.screen_id', '=', 'screens.id')
            ->whereIn('booking_screens.screen_id', $uniqueIds)
            ->whereIn('bookings.status', self::ACTIVE_STATUSES)
            ->where('bookings.end_time', '>=', now())
            ->select([
                'booking_screens.screen_id',
                'screens.name as screen_name',
                'bookings.id as booking_id',
                'bookings.booking_reference',
                'bookings.status as booking_status',
                'bookings.end_time as campaign_end_date',
            ])
            ->get();

        $blockedMap = [];
        foreach ($activeBookings as $row) {
            $screenId = (int) $row->screen_id;
            if (!isset($blockedMap[$screenId])) {
                $endDate = $row->campaign_end_date ? date('Y-m-d', strtotime($row->campaign_end_date)) : 'N/A';
                $blockedMap[$screenId] = [
                    'screen_id' => $screenId,
                    'screen_name' => $row->screen_name ?? "Screen #{$screenId}",
                    'booking_id' => (int) $row->booking_id,
                    'booking_reference' => $row->booking_reference ?? "BK-{$row->booking_id}",
                    'booking_status' => $row->booking_status,
                    'campaign_end_date' => $endDate,
                    'reason' => "Screen is reserved in active/future campaign #{$row->booking_reference} ({$row->booking_status}) ending {$endDate}.",
                ];
            }
        }

        $blockedIds = array_keys($blockedMap);
        $safeScreenIds = array_values(array_diff($uniqueIds, $blockedIds));
        $blockedItems = array_values($blockedMap);

        return [
            'total' => count($uniqueIds),
            'safe_count' => count($safeScreenIds),
            'blocked_count' => count($blockedItems),
            'safe_screen_ids' => $safeScreenIds,
            'blocked' => $blockedItems,
        ];
    }

    /**
     * Delete multiple screens safely. If forceSafeOnly is false and blocked screens exist,
     * returns validation details without deleting. If forceSafeOnly is true, soft-deletes the safe subset.
     */
    public function deleteScreens(array $screenIds, bool $forceSafeOnly = false): array
    {
        $validation = $this->validateDeletion($screenIds);

        if ($validation['total'] === 0) {
            return [
                'success' => false,
                'message' => 'No valid screen IDs provided.',
                'total' => 0,
                'deleted_count' => 0,
                'blocked_count' => 0,
                'deleted_ids' => [],
                'blocked' => [],
            ];
        }

        // If there are blocked screens and user did NOT explicitly request forceSafeOnly
        if ($validation['blocked_count'] > 0 && !$forceSafeOnly) {
            return [
                'success' => false,
                'message' => "{$validation['blocked_count']} of {$validation['total']} selected screen(s) cannot be deleted due to active/future campaigns.",
                'total' => $validation['total'],
                'safe_count' => $validation['safe_count'],
                'blocked_count' => $validation['blocked_count'],
                'safe_screen_ids' => $validation['safe_screen_ids'],
                'blocked' => $validation['blocked'],
            ];
        }

        $safeIds = $validation['safe_screen_ids'];

        if (empty($safeIds)) {
            return [
                'success' => false,
                'message' => 'All selected screens are protected by active/future campaigns and cannot be deleted.',
                'total' => $validation['total'],
                'deleted_count' => 0,
                'blocked_count' => $validation['blocked_count'],
                'deleted_ids' => [],
                'blocked' => $validation['blocked'],
            ];
        }

        // Perform transactional soft deletion on safe screens in chunks of 100
        DB::transaction(function () use ($safeIds) {
            $chunks = array_chunk($safeIds, 100);
            foreach ($chunks as $chunk) {
                $screens = Screen::whereIn('id', $chunk)->get();
                foreach ($screens as $screen) {
                    $screen->delete(); // Triggers Eloquent SoftDeletes & booted thumbnail removal
                }
            }

            // Log activity
            if (class_exists(ActivityLog::class)) {
                ActivityLog::log(
                    'screens_bulk_delete',
                    'Screen',
                    null,
                    'Soft deleted ' . count($safeIds) . ' screen(s).',
                    null,
                    ['deleted_ids' => $safeIds]
                );
            }
        });

        $message = count($safeIds) . ' screen(s) deleted successfully.';
        if ($validation['blocked_count'] > 0) {
            $message .= " {$validation['blocked_count']} screen(s) were protected due to active/future campaigns.";
        }

        return [
            'success' => true,
            'message' => $message,
            'total' => $validation['total'],
            'deleted_count' => count($safeIds),
            'blocked_count' => $validation['blocked_count'],
            'deleted_ids' => $safeIds,
            'blocked' => $validation['blocked'],
        ];
    }

    /**
     * Delete all active screens owned by a specific Screen Provider.
     */
    public function deleteProviderScreens(int $providerId, bool $forceSafeOnly = false): array
    {
        $provider = ScreenProvider::find($providerId);
        if (!$provider) {
            return [
                'success' => false,
                'message' => "Screen Provider #{$providerId} not found.",
                'total' => 0,
                'deleted_count' => 0,
                'blocked_count' => 0,
                'deleted_ids' => [],
                'blocked' => [],
            ];
        }

        // Retrieve all active (non-soft-deleted) screens owned by this provider
        $screenIds = Screen::query()
            ->where('screen_provider_id', $providerId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        if (empty($screenIds)) {
            return [
                'success' => true,
                'message' => "No active screens found for provider {$provider->provider_name}.",
                'total' => 0,
                'deleted_count' => 0,
                'blocked_count' => 0,
                'deleted_ids' => [],
                'blocked' => [],
            ];
        }

        $result = $this->deleteScreens($screenIds, $forceSafeOnly);

        // Record ScreenProviderActivity log if any screens were deleted
        if ($result['success'] && $result['deleted_count'] > 0) {
            ScreenProviderActivity::create([
                'screen_provider_id' => $providerId,
                'type' => 'screens_deleted',
                'description' => "Soft deleted {$result['deleted_count']} screens from provider {$provider->provider_name}.",
                'performed_by' => auth()->id(),
                'metadata' => [
                    'deleted_screen_ids' => $result['deleted_ids'],
                    'blocked_count' => $result['blocked_count'],
                ],
            ]);
        }

        return $result;
    }
}
