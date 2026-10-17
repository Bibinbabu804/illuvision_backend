<?php

namespace App\Services;

use App\Domain\Financial\Support\RevenueAllocation;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\Screen;
use App\Models\ScreenProviderActivity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for calculating and snapshotting revenue distribution
 * for every booked screen at booking creation time.
 *
 * Revenue is always calculated per booking_screen, never from the booking total,
 * ensuring mixed-ownership bookings (Illuvision + Screen Provider A + Screen Provider B)
 * are handled correctly.
 *
 * All values are snapshotted at booking creation and never recalculated,
 * guaranteeing historical accuracy for future settlements.
 *
 * PHASE 0.10 STRUCTURAL CHANGE (behaviour-preserving):
 * The three arithmetic expressions that produce the snapshot amounts were moved
 * into the pure domain helper `App\Domain\Financial\Support\RevenueAllocation`.
 * The helper reproduces the previous expressions exactly, including the order in
 * which rounding is applied. No formula, threshold, rounding mode or fallback
 * was changed.
 */
class RevenueDistributionService
{
    public function __construct(
        protected RevenueSplitResolverService $splitResolver
    ) {}

    /**
     * Process all booking_screens for a given booking and store revenue distribution snapshots.
     *
     * Determines ownership per screen, calculates gross revenue per screen using
     * the existing pricing allocation (subtotal / total screens), then computes
     * platform_share and provider_share using the authoritative RevenueSplitResolverService.
     *
     * @param Booking $booking  The booking (must already have bookingScreens created)
     * @return void
     */
    public function processBookingScreens(Booking $booking): void
    {
        $totalScreens = $booking->bookingScreens()->count();

        if ($totalScreens === 0) {
            return;
        }

        // Load screens with their provider and pricing plan info for split context
        $screenIds = $booking->bookingScreens()->pluck('screen_id')->toArray();
        $screens = Screen::with(['screenProvider', 'pricingPlan'])
            ->whereIn('id', $screenIds)
            ->get()
            ->keyBy('id');

        DB::transaction(function () use ($booking, $totalScreens, $screens) {
            foreach ($booking->bookingScreens as $bookingScreen) {
                $screen = $screens->get($bookingScreen->screen_id);

                if (!$screen) {
                    continue;
                }

                // Calculate gross revenue for this screen using existing pricing allocation
                $grossRevenue = RevenueAllocation::grossPerScreen(
                    $booking->final_amount,
                    $booking->subtotal ?? $booking->price ?? 0,
                    $totalScreens
                );

                // Resolve authoritative effective split via RevenueSplitResolverService
                $splitResult = $this->splitResolver->resolveForBookingScreen($bookingScreen, $booking);
                $illuvisionPct = (float) $splitResult['illuvision_percentage'];
                $providerPct = (float) $splitResult['screen_provider_percentage'];

                if ($splitResult['resolution_source'] === RevenueSplitResolverService::SOURCE_DIRECT_ILLUVISION || $screen->isOwnedByIlluvision()) {
                    // Illuvision-owned: store owner type and gross revenue, no provider split
                    $illuvisionAllocation = RevenueAllocation::illuvisionOwned($grossRevenue);

                    $bookingScreen->update([
                        'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
                        'screen_provider_id' => null,
                        'screen_provider_name' => null,
                        'gross_revenue' => $illuvisionAllocation['gross_revenue'],
                        'platform_share' => $illuvisionAllocation['platform_share'],
                        'provider_share' => $illuvisionAllocation['provider_share'],
                        'illuvision_percentage' => $illuvisionAllocation['illuvision_percentage'],
                        'screen_provider_percentage' => $illuvisionAllocation['screen_provider_percentage'],
                        'settlement_status' => 'not_applicable',
                    ]);
                } elseif ($screen->isOwnedByProvider() && $screen->screenProvider) {
                    // Provider-owned: calculate split using resolved percentages
                    $allocation = RevenueAllocation::split($grossRevenue, $illuvisionPct, $providerPct);

                    $bookingScreen->update([
                        'owner_type' => Screen::OWNER_TYPE_PROVIDER,
                        'screen_provider_id' => $screen->screen_provider_id,
                        'screen_provider_name' => $screen->screenProvider->provider_name,
                        'illuvision_percentage' => $illuvisionPct,
                        'screen_provider_percentage' => $providerPct,
                        'gross_revenue' => $allocation['gross_revenue'],
                        'platform_share' => $allocation['platform_share'],
                        'provider_share' => $allocation['provider_share'],
                        'settlement_status' => 'pending',
                    ]);

                    // Log activity for this revenue generation
                    $this->logRevenueGenerated($booking, $bookingScreen, $screen);
                }
            }
        });
    }

    /**
     * Get aggregated revenue statistics for a screen provider.
     *
     * All values are generated from booking_screen snapshots, never live calculations.
     *
     * @param int $screenProviderId
     * @return array
     */
    public function getProviderRevenueStats(int $screenProviderId): array
    {
        // Owned screens count
        $ownedScreensCount = Screen::where('screen_provider_id', $screenProviderId)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->count();

        // All booking_screens for this provider
        $bookingScreensQuery = BookingScreen::where('screen_provider_id', $screenProviderId)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                    Booking::STATUS_COMPLETED,
                ]);
            });

        $totalBookingScreenRecords = (clone $bookingScreensQuery)->count();
        $totalGrossRevenue = (float) (clone $bookingScreensQuery)->sum('gross_revenue');
        $totalPlatformRevenue = (float) (clone $bookingScreensQuery)->sum('platform_share');
        $totalProviderRevenue = (float) (clone $bookingScreensQuery)->sum('provider_share');

        // Unique campaign (booking) count
        $campaignCount = (clone $bookingScreensQuery)
            ->distinct('booking_id')
            ->count('booking_id');

        // Active campaigns (running or scheduled)
        $activeCampaigns = (clone $bookingScreensQuery)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                ]);
            })
            ->distinct('booking_id')
            ->count('booking_id');

        $avgRevenuePerScreen = $totalBookingScreenRecords > 0
            ? round($totalProviderRevenue / $totalBookingScreenRecords, 2)
            : 0;

        return [
            'owned_screens_count' => $ownedScreensCount,
            'campaign_count' => $campaignCount,
            'active_campaigns' => $activeCampaigns,
            'total_gross_revenue' => $totalGrossRevenue,
            'total_platform_revenue' => $totalPlatformRevenue,
            'total_provider_revenue' => $totalProviderRevenue,
            'average_revenue_per_screen' => $avgRevenuePerScreen,
            'total_screens_in_bookings' => $totalScreensInBookings,
        ];
    }

    /**
     * Get per-screen revenue breakdown for a provider's owned screens.
     *
     * @param int $screenProviderId
     * @return array
     */
    public function getProviderScreenRevenue(int $screenProviderId): array
    {
        $screens = Screen::where('screen_provider_id', $screenProviderId)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->with(['city', 'country'])
            ->get();

        $revenueDataMap = BookingScreen::where('screen_provider_id', $screenProviderId)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                    Booking::STATUS_COMPLETED,
                ]);
            })
            ->selectRaw('
                screen_id,
                COUNT(*) as booking_count,
                COALESCE(SUM(gross_revenue), 0) as gross_revenue,
                COALESCE(SUM(platform_share), 0) as platform_revenue,
                COALESCE(SUM(provider_share), 0) as provider_revenue
            ')
            ->groupBy('screen_id')
            ->get()
            ->keyBy('screen_id');

        $result = [];

        foreach ($screens as $screen) {
            $revenueData = $revenueDataMap->get($screen->id);

            $result[] = [
                'screen_id' => $screen->id,
                'screen_name' => $screen->name,
                'screen_code' => $screen->screen_code,
                'location' => $screen->location_name,
                'city' => $screen->city?->name,
                'country' => $screen->country?->name,
                'status' => $screen->status,
                'booking_count' => (int) ($revenueData?->booking_count ?? 0),
                'gross_revenue' => (float) ($revenueData?->gross_revenue ?? 0),
                'platform_revenue' => (float) ($revenueData?->platform_revenue ?? 0),
                'provider_revenue' => (float) ($revenueData?->provider_revenue ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Get booking history for a provider with revenue data.
     *
     * @param int $screenProviderId
     * @param array $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getProviderBookingHistory(int $screenProviderId, array $filters = [])
    {
        $perPage = $filters['per_page'] ?? 15;

        $groupConcatSql = DB::connection()->getDriverName() === 'sqlite'
            ? "GROUP_CONCAT(screens.name, ', ') as screen_names"
            : "GROUP_CONCAT(screens.name ORDER BY screens.name SEPARATOR ', ') as screen_names";

        $query = BookingScreen::where('booking_screens.screen_provider_id', $screenProviderId)
            ->where('booking_screens.owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->join('screens', 'screens.id', '=', 'booking_screens.screen_id')
            ->leftJoin('screen_provider_settlements', 'screen_provider_settlements.id', '=', 'booking_screens.settlement_id')
            ->where('bookings.status', '!=', Booking::STATUS_PAYMENT_PENDING)
            ->select(
                'booking_screens.booking_id',
                'bookings.campaign_title',
                'bookings.advertiser_name',
                'bookings.start_time',
                'bookings.end_time',
                'bookings.status as booking_status',
                'bookings.created_at as booking_created_at',
                DB::raw('COUNT(booking_screens.screen_id) as screen_count'),
                DB::raw('SUM(booking_screens.gross_revenue) as gross_revenue'),
                DB::raw('SUM(booking_screens.platform_share) as platform_share'),
                DB::raw('SUM(booking_screens.provider_share) as provider_share'),
                DB::raw('MIN(booking_screens.settlement_status) as settlement_status'),
                DB::raw('MAX(booking_screens.settlement_id) as settlement_id'),
                DB::raw($groupConcatSql),
                DB::raw('MAX(screen_provider_settlements.reference_number) as settlement_reference'),
                DB::raw('MAX(screen_provider_settlements.status) as settlement_status_summary')
            )
            ->groupBy(
                'booking_screens.booking_id',
                'bookings.campaign_title',
                'bookings.advertiser_name',
                'bookings.start_time',
                'bookings.end_time',
                'bookings.status',
                'bookings.created_at'
            )
            ->orderBy('bookings.created_at', 'desc');

        $paginator = $query->paginate($perPage);

        // Add campaign duration in minutes/label to each booking record
        $paginator->getCollection()->transform(function ($booking) {
            $start = Carbon::parse($booking->start_time);
            $end = Carbon::parse($booking->end_time);
            $durationMinutes = \App\Support\DurationFormatter::calculateMinutes($start, $end);
            $durationDays = $durationMinutes >= 1440 ? (int) round($durationMinutes / 1440) : round($durationMinutes / 1440, 4);
            $durationLabel = \App\Support\DurationFormatter::formatMinutes($durationMinutes);

            $booking->duration_minutes = $durationMinutes;
            $booking->duration_days = $durationDays;
            $booking->duration_label = $durationLabel;
            $booking->duration = $durationLabel;
            return $booking;
        });

        return $paginator;
    }

    /**
     * Log revenue generated activity for a screen provider.
     */
    protected function logRevenueGenerated(Booking $booking, BookingScreen $bookingScreen, Screen $screen): void
    {
        ScreenProviderActivity::create([
            'screen_provider_id' => $screen->screen_provider_id,
            'type' => 'revenue_generated',
            'description' => sprintf(
                'Revenue generated: ₹%s from booking #%s (%s) on screen "%s" — Illuvision: ₹%s, Provider: ₹%s',
                number_format($bookingScreen->gross_revenue, 2),
                $booking->id,
                $booking->campaign_title ?: $booking->title,
                $screen->name,
                number_format($bookingScreen->platform_share, 2),
                number_format($bookingScreen->provider_share, 2)
            ),
            'metadata' => [
                'booking_id' => $booking->id,
                'booking_screen_id' => $bookingScreen->id,
                'screen_id' => $screen->id,
                'screen_name' => $screen->name,
                'gross_revenue' => (float) $bookingScreen->gross_revenue,
                'platform_share' => (float) $bookingScreen->platform_share,
                'provider_share' => (float) $bookingScreen->provider_share,
                'illuvision_percentage' => (float) $bookingScreen->illuvision_percentage,
                'provider_percentage' => (float) $bookingScreen->screen_provider_percentage,
                'settlement_status' => $bookingScreen->settlement_status,
            ],
        ]);
    }
}
