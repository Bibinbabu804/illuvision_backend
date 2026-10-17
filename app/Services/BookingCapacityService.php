<?php

namespace App\Services;

use App\Models\Screen;
use App\Support\BookingEligibilityResult;
use App\Support\Configs\BookingConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BookingCapacityService
{
    /**
     * Booking statuses that consume daily ad capacity and physical inventory.
     *
     * These campaigns have actively reserved inventory.
     */
    const CAPACITY_CONSUMING_STATUSES = [
        'payment_pending',
        'pending_approval',
        'approved',
        'scheduled',
        'running',
    ];

    public function __construct(
        protected BookingConfig $bookingConfig,
        protected InventoryService $inventoryService
    ) {}

    /**
     * Authoritative eligibility oracle for screen booking requests.
     * Evaluates all three capacity dimensions:
     * 1. Daily booking quota (max_ads_per_day per calendar day per screen)
     * 2. Fixed-screen physical/time availability (intraday / timestamp interval overlap)
     * 3. Mobile fleet capacity (concurrent unit line-sweep over fleet size)
     *
     * @param  array       $items             Array of screen IDs [1, 2] or items [['screen_id' => 1, 'quantity' => 1]]
     * @param  string      $startTime         Start date or datetime (Y-m-d or Y-m-d H:i:s)
     * @param  string      $endTime           End date or datetime (Y-m-d or Y-m-d H:i:s)
     * @param  int|null    $excludeBookingId  Optional booking ID to self-exclude during updates
     * @param  array       $options           Optional flags (e.g. daily_start_time, daily_end_time)
     * @return BookingEligibilityResult
     */
    public function checkEligibility(
        array $items,
        string $startTime,
        string $endTime,
        ?int $excludeBookingId = null,
        array $options = []
    ): BookingEligibilityResult {
        // 1. Normalize items into unique, deterministically-ordered screen items
        $normalizedItems = $this->normalizeItems($items);
        if (empty($normalizedItems)) {
            return BookingEligibilityResult::ineligible(
                BookingEligibilityResult::CODE_ASSET_UNAVAILABLE,
                BookingEligibilityResult::TYPE_ASSET_UNAVAILABLE,
                ['No screens selected for booking eligibility check.']
            );
        }

        // 2. Normalize and validate time intervals
        $startDateTime = $this->inventoryService->normalizeDateTime($startTime, false);
        $endDateTime = $this->inventoryService->normalizeDateTime($endTime, true);

        if (Carbon::parse($endDateTime)->lessThanOrEqualTo(Carbon::parse($startDateTime))) {
            return BookingEligibilityResult::ineligible(
                BookingEligibilityResult::CODE_INVALID_INTERVAL,
                BookingEligibilityResult::TYPE_ASSET_UNAVAILABLE,
                ['End time must be after start time.']
            );
        }

        $startDate = Carbon::parse($startDateTime)->format('Y-m-d');
        $endDate = Carbon::parse($endDateTime)->format('Y-m-d');
        $screenIds = array_column($normalizedItems, 'screen_id');

        // 3. Inspect Screen models and operational status
        $screens = Screen::with('pricingPlan')->whereIn('id', $screenIds)->get()->keyBy('id');
        $conflicts = [];
        $conflictDates = [];
        $dailyBreakdown = [];
        $physicalBreakdown = [];
        $screensBreakdown = [];

        foreach ($normalizedItems as $item) {
            $sid = $item['screen_id'];
            $screen = $screens->get($sid);

            $screensBreakdown[$sid] = [
                'screen_id' => $sid,
                'screen_name' => $screen?->name ?? "Screen #{$sid}",
                'eligible' => true,
                'available' => true,
                'error_code' => null,
                'conflict_type' => null,
                'conflict_dates' => [],
                'message' => null,
                'details' => [
                    'screen_id' => $sid,
                ],
            ];

            if (!$screen) {
                $screensBreakdown[$sid]['eligible'] = false;
                $screensBreakdown[$sid]['available'] = false;
                $screensBreakdown[$sid]['error_code'] = BookingEligibilityResult::CODE_ASSET_UNAVAILABLE;
                $screensBreakdown[$sid]['conflict_type'] = BookingEligibilityResult::TYPE_ASSET_UNAVAILABLE;
                $screensBreakdown[$sid]['message'] = "Screen #{$sid} not found in inventory.";
                $screensBreakdown[$sid]['details']['reason'] = 'asset_not_found';

                $conflicts[] = [
                    'type' => BookingEligibilityResult::TYPE_ASSET_UNAVAILABLE,
                    'code' => BookingEligibilityResult::CODE_ASSET_UNAVAILABLE,
                    'screen_id' => $sid,
                    'message' => "Screen #{$sid} not found in inventory.",
                ];
                continue;
            }

            if (!$screen->is_bookable || $screen->booking_status === 'sold_out' || $screen->status !== 'active') {
                $screensBreakdown[$sid]['eligible'] = false;
                $screensBreakdown[$sid]['available'] = false;
                $screensBreakdown[$sid]['error_code'] = BookingEligibilityResult::CODE_ASSET_UNAVAILABLE;
                $screensBreakdown[$sid]['conflict_type'] = BookingEligibilityResult::TYPE_ASSET_UNAVAILABLE;
                $screensBreakdown[$sid]['message'] = "Screen '{$screen->name}' is currently marked as unavailable or sold out.";
                $screensBreakdown[$sid]['details']['reason'] = 'asset_unavailable';

                $conflicts[] = [
                    'type' => BookingEligibilityResult::TYPE_ASSET_UNAVAILABLE,
                    'code' => BookingEligibilityResult::CODE_ASSET_UNAVAILABLE,
                    'screen_id' => $sid,
                    'screen_name' => $screen->name,
                    'message' => "Screen '{$screen->name}' is currently marked as unavailable or sold out.",
                ];
            }
        }

        if (!empty($conflicts)) {
            $details = [
                'screen_id' => $conflicts[0]['screen_id'] ?? $screenIds[0],
                'conflict_dates' => [],
                'screens' => array_values($screensBreakdown),
            ];

            return BookingEligibilityResult::ineligible(
                BookingEligibilityResult::CODE_ASSET_UNAVAILABLE,
                BookingEligibilityResult::TYPE_ASSET_UNAVAILABLE,
                $conflicts,
                [],
                $dailyBreakdown,
                $physicalBreakdown,
                array_values($screensBreakdown),
                $details
            );
        }

        // 4. Dimension 1: Validate Daily Booking Quotas across all calendar days
        $dailyConflicts = $this->validateScreenDailyCapacity($screenIds, $startDate, $endDate, $excludeBookingId);
        $hasDailyConflict = !empty($dailyConflicts);

        if ($hasDailyConflict) {
            foreach ($dailyConflicts as $dc) {
                $sid = $dc['screen_id'];
                $conflictDates[] = $dc['date'];

                if (isset($screensBreakdown[$sid])) {
                    $screensBreakdown[$sid]['eligible'] = false;
                    $screensBreakdown[$sid]['available'] = false;
                    $screensBreakdown[$sid]['error_code'] = BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED;
                    $screensBreakdown[$sid]['conflict_type'] = BookingEligibilityResult::TYPE_DAILY_LIMIT;
                    $screensBreakdown[$sid]['conflict_dates'][] = $dc['date'];
                    $screensBreakdown[$sid]['current_bookings'] = max($screensBreakdown[$sid]['current_bookings'] ?? 0, $dc['current_ads']);
                    $screensBreakdown[$sid]['maximum_bookings'] = $dc['max_ads'];
                    $screensBreakdown[$sid]['message'] = "Screen '{$dc['screen_name']}' has reached its maximum daily advertisement capacity ({$dc['current_ads']}/{$dc['max_ads']}) on {$dc['date']}.";
                    $screensBreakdown[$sid]['details']['current_bookings'] = max($screensBreakdown[$sid]['details']['current_bookings'] ?? 0, $dc['current_ads']);
                    $screensBreakdown[$sid]['details']['maximum_bookings'] = $dc['max_ads'];
                    $screensBreakdown[$sid]['details']['conflict_dates'] = array_values(array_unique($screensBreakdown[$sid]['conflict_dates']));
                }

                $conflicts[] = [
                    'type' => BookingEligibilityResult::TYPE_DAILY_LIMIT,
                    'code' => BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED,
                    'screen_id' => $dc['screen_id'],
                    'screen_name' => $dc['screen_name'],
                    'date' => $dc['date'],
                    'current_ads' => $dc['current_ads'],
                    'max_ads' => $dc['max_ads'],
                    'message' => "Screen '{$dc['screen_name']}' has reached its maximum daily advertisement capacity ({$dc['current_ads']}/{$dc['max_ads']}) on {$dc['date']}.",
                ];
            }
        }

        // 5. Dimension 2 & 3: Validate Physical / Time-Interval / Mobile Fleet Capacity
        $hasTimeConflict = false;
        $hasFleetConflict = false;

        foreach ($normalizedItems as $item) {
            $sid = $item['screen_id'];
            $reqQty = $item['quantity'];
            $screen = $screens->get($sid);
            $isMobile = (bool) ($screen->is_mobile || in_array($screen->asset_type, ['mobile', 'vehicle', 'fleet']));

            if ($isMobile) {
                // Dimension 3: Mobile Fleet line sweep
                $avail = $this->inventoryService->getAvailableFleet($sid, $startDateTime, $endDateTime, $excludeBookingId);
                $physicalBreakdown[$sid] = [
                    'is_mobile' => true,
                    'fleet_size' => (int) $screen->fleet_size,
                    'available_units' => $avail,
                    'requested_units' => $reqQty,
                ];

                if ($reqQty > $avail) {
                    $hasFleetConflict = true;

                    if (isset($screensBreakdown[$sid])) {
                        $screensBreakdown[$sid]['eligible'] = false;
                        $screensBreakdown[$sid]['available'] = false;
                        if (empty($screensBreakdown[$sid]['error_code'])) {
                            $screensBreakdown[$sid]['error_code'] = BookingEligibilityResult::CODE_FLEET_CAPACITY_EXCEEDED;
                            $screensBreakdown[$sid]['conflict_type'] = BookingEligibilityResult::TYPE_FLEET_CAPACITY;
                            $screensBreakdown[$sid]['message'] = "Asset '{$screen->name}' has only {$avail} units available, but {$reqQty} requested.";
                        }
                        $screensBreakdown[$sid]['details']['requested_quantity'] = $reqQty;
                        $screensBreakdown[$sid]['details']['available_quantity'] = $avail;
                        $screensBreakdown[$sid]['details']['fleet_size'] = (int) $screen->fleet_size;
                    }

                    $conflicts[] = [
                        'type' => BookingEligibilityResult::TYPE_FLEET_CAPACITY,
                        'code' => BookingEligibilityResult::CODE_FLEET_CAPACITY_EXCEEDED,
                        'screen_id' => $sid,
                        'screen_name' => $screen->name,
                        'available_units' => $avail,
                        'requested_units' => $reqQty,
                        'message' => "Asset '{$screen->name}' has only {$avail} units available, but {$reqQty} requested.",
                    ];
                }
            } else {
                // Dimension 2: Fixed Screen time interval conflict (for intraday / minute / hourly windows)
                $plan = $screen->pricingPlan;
                $isMinutePlan = $plan && in_array($plan->minimum_duration_type, ['Minute', 'Hour']);
                $sTime = Carbon::parse($startDateTime);
                $eTime = Carbon::parse($endDateTime);
                $isIntraday = ($sTime->format('H:i:s') !== '00:00:00' || ($eTime->format('H:i:s') !== '23:59:59' && $eTime->format('H:i:s') !== '00:00:00')) || $isMinutePlan;

                $timeConflict = $isIntraday ? $this->hasScreenIntervalConflict($sid, $startDateTime, $endDateTime, $excludeBookingId) : false;
                $physicalBreakdown[$sid] = [
                    'is_mobile' => false,
                    'has_time_conflict' => $timeConflict,
                ];

                if ($timeConflict) {
                    $hasTimeConflict = true;

                    if (isset($screensBreakdown[$sid])) {
                        $screensBreakdown[$sid]['eligible'] = false;
                        $screensBreakdown[$sid]['available'] = false;
                        if (empty($screensBreakdown[$sid]['error_code'])) {
                            $screensBreakdown[$sid]['error_code'] = BookingEligibilityResult::CODE_TIME_CONFLICT;
                            $screensBreakdown[$sid]['conflict_type'] = BookingEligibilityResult::TYPE_TIME_CONFLICT;
                            $screensBreakdown[$sid]['message'] = "Screen '{$screen->name}' is not available for the requested time window.";
                        }
                        $screensBreakdown[$sid]['details']['conflict_start'] = $startDateTime;
                        $screensBreakdown[$sid]['details']['conflict_end'] = $endDateTime;
                    }

                    $conflicts[] = [
                        'type' => BookingEligibilityResult::TYPE_TIME_CONFLICT,
                        'code' => BookingEligibilityResult::CODE_TIME_CONFLICT,
                        'screen_id' => $sid,
                        'screen_name' => $screen->name,
                        'message' => "Screen '{$screen->name}' is not available for the requested time window.",
                    ];
                }
            }
        }

        // 6. Return Clean Result or Classify Primary Ineligibility Precedence
        $uniqueConflictDates = array_values(array_unique($conflictDates));

        if (empty($conflicts)) {
            $details = [
                'screens' => array_values($screensBreakdown),
            ];

            return BookingEligibilityResult::eligible(
                $dailyBreakdown,
                $physicalBreakdown,
                array_values($screensBreakdown),
                $details
            );
        }

        // Primary error code precedence: Daily limit > Time conflict > Fleet capacity > Asset unavailable
        if ($hasDailyConflict) {
            $primaryCode = BookingEligibilityResult::CODE_DAILY_CAPACITY_EXCEEDED;
            $primaryType = BookingEligibilityResult::TYPE_DAILY_LIMIT;
        } elseif ($hasTimeConflict) {
            $primaryCode = BookingEligibilityResult::CODE_TIME_CONFLICT;
            $primaryType = BookingEligibilityResult::TYPE_TIME_CONFLICT;
        } elseif ($hasFleetConflict) {
            $primaryCode = BookingEligibilityResult::CODE_FLEET_CAPACITY_EXCEEDED;
            $primaryType = BookingEligibilityResult::TYPE_FLEET_CAPACITY;
        } else {
            $primaryCode = BookingEligibilityResult::CODE_ASSET_UNAVAILABLE;
            $primaryType = BookingEligibilityResult::TYPE_ASSET_UNAVAILABLE;
        }

        $primaryConflict = $conflicts[0] ?? [];
        $primaryScreenId = $primaryConflict['screen_id'] ?? ($screenIds[0] ?? null);

        $details = [
            'screen_id' => $primaryScreenId,
            'conflict_dates' => $uniqueConflictDates,
            'screens' => array_values($screensBreakdown),
        ];

        if ($hasDailyConflict) {
            $firstDaily = $dailyConflicts[0] ?? [];
            $details['current_bookings'] = $firstDaily['current_ads'] ?? null;
            $details['maximum_bookings'] = $firstDaily['max_ads'] ?? $this->getMaxAdsPerDay();
        } elseif ($hasTimeConflict) {
            $details['conflict_start'] = $startDateTime;
            $details['conflict_end'] = $endDateTime;
        } elseif ($hasFleetConflict) {
            $firstFleet = collect($conflicts)->firstWhere('type', BookingEligibilityResult::TYPE_FLEET_CAPACITY);
            if ($firstFleet) {
                $details['requested_quantity'] = $firstFleet['requested_units'] ?? null;
                $details['available_quantity'] = $firstFleet['available_units'] ?? null;
            }
        }

        return BookingEligibilityResult::ineligible(
            $primaryCode,
            $primaryType,
            $conflicts,
            $uniqueConflictDates,
            $dailyBreakdown,
            $physicalBreakdown,
            array_values($screensBreakdown),
            $details
        );
    }

    /**
     * Backward-compatible helper method returning list of conflict error strings.
     */
    public function validateBookingCapacity(
        array $items,
        string $startTime,
        string $endTime,
        ?int $excludeBookingId = null,
        array $options = []
    ): array {
        $result = $this->checkEligibility($items, $startTime, $endTime, $excludeBookingId, $options);
        if ($result->isEligible()) {
            return [];
        }

        $messages = [];
        foreach ($result->getConflicts() as $conflict) {
            if (is_string($conflict)) {
                $messages[] = $conflict;
            } elseif (is_array($conflict) && isset($conflict['message'])) {
                $messages[] = $conflict['message'];
            }
        }

        return $messages;
    }

    /**
     * Validate daily ad capacity for selected screens across a date range.
     *
     * Iterates date-by-date and screen-by-screen, counting active ads
     * that have reserved inventory on each specific day.
     *
     * Max ads per day is sourced from BookingConfig (settings-managed).
     *
     * @param  array     $screenIds
     * @param  string    $startDate         Y-m-d
     * @param  string    $endDate           Y-m-d
     * @param  int|null  $excludeBookingId  Optional booking ID to self-exclude
     * @return array     List of capacity conflicts:
     *                   [['screen_id' => 1, 'screen_name' => '...', 'date' => '2026-06-10', 'current_ads' => 12, 'max_ads' => 12]]
     */
    public function validateScreenDailyCapacity(
        array $screenIds,
        string $startDate,
        string $endDate,
        ?int $excludeBookingId = null
    ): array {
        if (empty($screenIds)) {
            return [];
        }

        $maxAdsPerDay = $this->bookingConfig->maxAdsPerDay();
        $conflicts = [];
        $screenNames = Screen::whereIn('id', $screenIds)->pluck('name', 'id');

        // Fetch all active booking intervals overlapping the range in one efficient query
        $intervalsByScreen = $this->getActiveBookingIntervalsByScreen($screenIds, $startDate, $endDate, $excludeBookingId);

        // Iterate each calendar day in the range
        $current = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');

            foreach ($screenIds as $screenId) {
                $intervals = $intervalsByScreen[$screenId] ?? [];
                $count = 0;

                foreach ($intervals as $interval) {
                    // Booking covers this day if its date range includes $current
                    if ($current->between($interval->start_date, $interval->end_date)) {
                        $count++;
                    }
                }

                if ($count >= $maxAdsPerDay) {
                    $conflicts[] = [
                        'screen_id'   => (int) $screenId,
                        'screen_name' => $screenNames[$screenId] ?? 'Unknown',
                        'date'        => $dateStr,
                        'current_ads' => $count,
                        'max_ads'     => $maxAdsPerDay,
                    ];
                }
            }

            $current->addDay();
        }

        return $conflicts;
    }

    /**
     * Get the maximum ads per day value (for UI display).
     */
    public function getMaxAdsPerDay(): int
    {
        return $this->bookingConfig->maxAdsPerDay();
    }

    /**
     * Check if a single screen has available capacity for a specific date.
     */
    public function screenHasAvailableCapacity(int $screenId, string $date, ?int $excludeBookingId = null): bool
    {
        $conflicts = $this->validateScreenDailyCapacity([$screenId], $date, $date, $excludeBookingId);
        return empty($conflicts);
    }

    /**
     * Check if a screen has an active booking that directly conflicts with the requested timestamp interval.
     * Uses strict interval overlap: existing_start < requested_end AND existing_end > requested_start.
     * Adjacent intervals (e.g. 10:00-10:30 and 10:30-11:00) do NOT conflict.
     */
    public function hasScreenIntervalConflict(
        int $screenId,
        string $startDateTime,
        string $endDateTime,
        ?int $excludeBookingId = null
    ): bool {
        $start = Carbon::parse($startDateTime)->format('Y-m-d H:i:s');
        $end = Carbon::parse($endDateTime)->format('Y-m-d H:i:s');

        if (Carbon::parse($end)->lessThanOrEqualTo(Carbon::parse($start))) {
            return true;
        }

        $campaignConflict = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->where('booking_screens.screen_id', $screenId)
            ->whereIn('bookings.status', static::CAPACITY_CONSUMING_STATUSES)
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
            ->whereIn('status', static::CAPACITY_CONSUMING_STATUSES)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->when($excludeBookingId, fn($q) => $q->where('id', '!=', $excludeBookingId))
            ->exists();
    }

    /**
     * Fetch all active booking intervals for the given screens that overlap
     * the date range, grouped by screen_id for efficient per-day counting.
     */
    protected function getActiveBookingIntervalsByScreen(
        array $screenIds,
        string $startDate,
        string $endDate,
        ?int $excludeBookingId = null
    ): array {
        $endOfEndDate = $endDate . ' 23:59:59';
        $startOfStartDate = $startDate . ' 00:00:00';

        // Query via booking_screens (campaign flow)
        $campaignBookings = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereIn('booking_screens.screen_id', $screenIds)
            ->where('bookings.start_time', '<=', $endOfEndDate)
            ->where('bookings.end_time', '>=', $startOfStartDate)
            ->whereIn('bookings.status', static::CAPACITY_CONSUMING_STATUSES)
            ->when($excludeBookingId, fn($q) => $q->where('bookings.id', '!=', $excludeBookingId))
            ->select(
                'booking_screens.screen_id',
                DB::raw('DATE(bookings.start_time) as booking_start_date'),
                DB::raw('DATE(bookings.end_time) as booking_end_date')
            )
            ->get();

        // Query via bookings.screen_id (legacy single-screen flow)
        $legacyBookings = DB::table('bookings')
            ->whereIn('screen_id', $screenIds)
            ->whereNotNull('screen_id')
            ->where('start_time', '<=', $endOfEndDate)
            ->where('end_time', '>=', $startOfStartDate)
            ->whereIn('status', static::CAPACITY_CONSUMING_STATUSES)
            ->when($excludeBookingId, fn($q) => $q->where('id', '!=', $excludeBookingId))
            ->select(
                'screen_id',
                DB::raw('DATE(start_time) as booking_start_date'),
                DB::raw('DATE(end_time) as booking_end_date')
            )
            ->get();

        $grouped = [];

        foreach ($campaignBookings as $row) {
            $sid = (int) $row->screen_id;
            $grouped[$sid][] = (object) [
                'start_date' => Carbon::parse($row->booking_start_date)->startOfDay(),
                'end_date'   => Carbon::parse($row->booking_end_date)->endOfDay(),
            ];
        }

        foreach ($legacyBookings as $row) {
            $sid = (int) $row->screen_id;
            $grouped[$sid][] = (object) [
                'start_date' => Carbon::parse($row->booking_start_date)->startOfDay(),
                'end_date'   => Carbon::parse($row->booking_end_date)->endOfDay(),
            ];
        }

        return $grouped;
    }

    /**
     * Normalize items input into array of ['screen_id' => int, 'quantity' => int]
     * sorted deterministically by screen_id to prevent database deadlocks.
     */
    protected function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (is_numeric($item)) {
                $sid = (int) $item;
                $qty = 1;
            } elseif (is_array($item)) {
                $sid = (int) ($item['screen_id'] ?? $item['asset_id'] ?? 0);
                $qty = max(1, (int) ($item['quantity'] ?? 1));
            } else {
                continue;
            }

            if ($sid <= 0) {
                continue;
            }

            // Deduplicate: take maximum requested quantity if provided multiple times
            if (isset($normalized[$sid])) {
                $normalized[$sid]['quantity'] = max($normalized[$sid]['quantity'], $qty);
            } else {
                $normalized[$sid] = [
                    'screen_id' => $sid,
                    'quantity' => $qty,
                ];
            }
        }

        // Sort ascending by screen_id for deterministic row locking order
        ksort($normalized);

        return array_values($normalized);
    }
}
