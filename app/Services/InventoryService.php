<?php

namespace App\Services;

use App\Models\Screen;
use App\Enums\AssetType;

use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Capacity consuming booking statuses.
     */
    const CAPACITY_STATUSES = [
        'payment_pending',
        'pending_approval',
        'approved',
        'scheduled',
        'running',
    ];

    /**
     * Lock the inventory (screen/fleet) rows for the exact screen_ids that a
     * booking is about to validate and create (BP6 P0-1).
     *
     * Serializes concurrent bookings for the same assets: a second request
     * blocks here until the first transaction commits, so its availability
     * reads (booking_screens / bookings) always observe the committed state
     * of the first booking. The lock is taken in deterministic id order to
     * avoid lock-order deadlocks.
     *
     * Must be called inside a database transaction (the lock is held until
     * the transaction commits or rolls back).
     *
     * @return \Illuminate\Database\Eloquent\Collection|Screen[]
     */
    public function lockScreensForBooking(array $screenIds): \Illuminate\Database\Eloquent\Collection
    {
        $screenIds = array_values(array_unique(array_map('intval', $screenIds)));
        if (empty($screenIds)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return Screen::query()
            ->whereIn('id', $screenIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Normalize a date or datetime string to a standard Y-m-d H:i:s string.
     */
    public function normalizeDateTime(string $datetime, bool $isEnd = false): string
    {
        $datetime = trim($datetime);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $datetime)) {
            return $isEnd ? $datetime . ' 23:59:59' : $datetime . ' 00:00:00';
        }
        return \Carbon\Carbon::parse($datetime)->format('Y-m-d H:i:s');
    }

    /**
     * Get maximum available fleet units for a mobile asset over a date or datetime range.
     * Evaluates concurrent overlapping reservations using a line-sweep algorithm so
     * non-overlapping intraday bookings do not falsely sum together.
     */
    public function getAvailableFleet(int $assetId, string $startDate, string $endDate, ?int $excludeBookingId = null): int
    {
        $asset = Screen::find($assetId);
        if (!$asset) {
            return 0;
        }

        $startDateTime = $this->normalizeDateTime($startDate, false);
        $endDateTime = $this->normalizeDateTime($endDate, true);

        // Reject invalid zero-duration or inverted intervals
        if (\Carbon\Carbon::parse($endDateTime)->lessThanOrEqualTo(\Carbon\Carbon::parse($startDateTime))) {
            return 0;
        }

        // Fixed screen: binary availability
        if (!$asset->is_mobile) {
            if (!$asset->is_bookable || $asset->booking_status === 'sold_out') {
                return 0;
            }
            $hasConflict = $this->hasFixedScreenConflict($assetId, $startDateTime, $endDateTime, $excludeBookingId);
            return $hasConflict ? 0 : 1;
        }

        // Mobile asset: query all active bookings overlapping the requested interval [startDateTime, endDateTime]
        $overlappingCampaignBookings = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->where('booking_screens.screen_id', $assetId)
            ->whereIn('bookings.status', self::CAPACITY_STATUSES)
            ->where('bookings.start_time', '<', $endDateTime)
            ->where('bookings.end_time', '>', $startDateTime)
            ->when($excludeBookingId, fn($q) => $q->where('bookings.id', '!=', $excludeBookingId))
            ->select(
                'bookings.id',
                'bookings.start_time',
                'bookings.end_time',
                DB::raw('COALESCE(booking_screens.quantity, 1) as quantity')
            )
            ->get();

        $legacyBookings = DB::table('bookings')
            ->where('screen_id', $assetId)
            ->whereNotNull('screen_id')
            ->whereIn('status', self::CAPACITY_STATUSES)
            ->where('start_time', '<', $endDateTime)
            ->where('end_time', '>', $startDateTime)
            ->whereNotIn('id', $overlappingCampaignBookings->pluck('id')->all())
            ->when($excludeBookingId, fn($q) => $q->where('id', '!=', $excludeBookingId))
            ->select('id', 'start_time', 'end_time', DB::raw('1 as quantity'))
            ->get();

        $allOverlapping = $overlappingCampaignBookings->concat($legacyBookings);

        $maxConcurrentReserved = $this->calculateMaxConcurrentUnits($allOverlapping);

        return max(0, (int) $asset->fleet_size - $maxConcurrentReserved);
    }

    /**
     * Calculate the maximum concurrent reserved units from a collection of booking intervals.
     * Uses event-point line sweep algorithm. End events are processed before start events at identical
     * timestamps so adjacent intervals (e.g. 10:00-11:00 and 11:00-12:00) do not produce false spikes.
     */
    public function calculateMaxConcurrentUnits(\Illuminate\Support\Collection $bookings): int
    {
        if ($bookings->isEmpty()) {
            return 0;
        }

        $events = [];
        foreach ($bookings as $b) {
            $qty = (int) $b->quantity;
            if ($qty <= 0) continue;

            $startSec = \Carbon\Carbon::parse($b->start_time)->timestamp;
            $endSec = \Carbon\Carbon::parse($b->end_time)->timestamp;

            // Type -1 for end, +1 for start
            $events[] = ['time' => $startSec, 'type' => 1, 'qty' => $qty];
            $events[] = ['time' => $endSec, 'type' => -1, 'qty' => -$qty];
        }

        // Sort: ascending by timestamp; if equal, end events (type -1) before start events (type 1)
        usort($events, function ($a, $b) {
            if ($a['time'] === $b['time']) {
                return $a['type'] <=> $b['type'];
            }
            return $a['time'] <=> $b['time'];
        });

        $current = 0;
        $maxConcurrent = 0;

        foreach ($events as $event) {
            $current += $event['qty'];
            if ($current > $maxConcurrent) {
                $maxConcurrent = $current;
            }
        }

        return $maxConcurrent;
    }

    /**
     * Check if a fixed screen has any conflicting active booking in the given interval.
     */
    public function hasFixedScreenConflict(int $screenId, string $startDateTime, string $endDateTime, ?int $excludeBookingId = null): bool
    {
        $start = $this->normalizeDateTime($startDateTime, false);
        $end = $this->normalizeDateTime($endDateTime, true);

        $campaignConflict = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->where('booking_screens.screen_id', $screenId)
            ->whereIn('bookings.status', self::CAPACITY_STATUSES)
            ->where('bookings.start_time', '<', $end)
            ->where('bookings.end_time', '>', $start)
            ->when($excludeBookingId, fn($q) => $q->where('bookings.id', '!=', $excludeBookingId))
            ->exists();

        if ($campaignConflict) {
            return true;
        }

        return DB::table('bookings')
            ->where('screen_id', $screenId)
            ->whereNotNull('screen_id')
            ->whereIn('status', self::CAPACITY_STATUSES)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->when($excludeBookingId, fn($q) => $q->where('id', '!=', $excludeBookingId))
            ->exists();
    }

    /**
     * Validate fleet availability for multiple requested items.
     * @param array $items Array of ['asset_id' => int, 'quantity' => int]
     * @param string $startDate
     * @param string $endDate
     * @param int|null $excludeBookingId
     * @return array List of conflict messages (empty if valid)
     */
    public function validateFleetAvailability(array $items, string $startDate, string $endDate, ?int $excludeBookingId = null): array
    {
        $startDateTime = $this->normalizeDateTime($startDate, false);
        $endDateTime = $this->normalizeDateTime($endDate, true);

        if (\Carbon\Carbon::parse($endDateTime)->lessThanOrEqualTo(\Carbon\Carbon::parse($startDateTime))) {
            return ['End time must be after start time.'];
        }

        $conflicts = [];

        foreach ($items as $item) {
            $assetId = $item['asset_id'] ?? $item['screen_id'] ?? null;
            if (!$assetId) continue;
            $reqQuantity = max(1, (int) ($item['quantity'] ?? 1));

            $asset = Screen::find($assetId);
            if (!$asset) {
                $conflicts[] = "Asset #{$assetId} not found.";
                continue;
            }

            $avail = $this->getAvailableFleet($assetId, $startDateTime, $endDateTime, $excludeBookingId);
            if ($reqQuantity > $avail) {
                if ($asset->is_mobile) {
                    $conflicts[] = "Asset '{$asset->name}' has only {$avail} units available, but {$reqQuantity} requested.";
                } else {
                    $conflicts[] = "Screen '{$asset->name}' is not available for the requested time interval.";
                }
            }
        }

        return $conflicts;
    }
}
