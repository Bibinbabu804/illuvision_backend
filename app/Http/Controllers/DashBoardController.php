<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\Screen;
use App\Models\ScreenProvider;
use App\Models\ScreenProviderSettlement;
use App\Models\ContactEnquiry;
use App\Services\Booking\BookingAnalyticsService;
use App\Services\Campaign\ScreenAnalyticsService;
use App\Services\Settlement\RevenueAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashBoardController extends Controller
{
    public function __construct(
        protected BookingAnalyticsService $bookingAnalytics,
        protected ScreenAnalyticsService $screenAnalytics,
        protected RevenueAnalyticsService $revenueAnalytics,
    ) {}

    /**
     * GET /dashboard
     * Comprehensive dashboard data for the admin overview page.
     */
    public function index(Request $request)
    {
        $now = Carbon::now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();

        // ── Date ranges ──
        $weekStart = $now->copy()->startOfWeek();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

        // ── Screen Stats ──
        $totalScreens = Screen::count();
        $offlineScreens = Screen::where('status', '!=', 'active')->count();
        $availableScreens = Screen::where('status', 'active')->where('booking_status', 'available')->count();
        $soldOutScreens = Screen::where('booking_status', 'sold_out')->count();
        $maintenanceScreens = Screen::where('status', 'maintenance')->count();

        $activeStatuses = ['pending_approval', 'approved', 'scheduled', 'running', 'media_review_pending'];

        $activeScreenIds = Booking::whereDate('start_time', '<=', $todayEnd->format('Y-m-d'))
            ->whereDate('end_time', '>=', $todayStart->format('Y-m-d'))
            ->whereIn('status', $activeStatuses)
            ->whereNotNull('screen_id')
            ->distinct('screen_id')
            ->pluck('screen_id');

        $campaignScreenIds = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereDate('bookings.start_time', '<=', $todayEnd->format('Y-m-d'))
            ->whereDate('bookings.end_time', '>=', $todayStart->format('Y-m-d'))
            ->whereIn('bookings.status', $activeStatuses)
            ->distinct('booking_screens.screen_id')
            ->pluck('booking_screens.screen_id');

        $activeScreensCount = $activeScreenIds->merge($campaignScreenIds)->unique()->count();

        // Utilization rate (current month)
        $screenOverview = $this->screenAnalytics->overview([
            'date_from' => $monthStart->format('Y-m-d'),
            'date_to' => $monthEnd->format('Y-m-d'),
        ]);
        $utilizationRate = $screenOverview['occupancy_rate'];

        // ── Booking Stats ──
        $bookingsToday = Booking::whereDate('created_at', $todayStart->format('Y-m-d'))->count();

        $confirmed = Booking::whereIn('status', ['approved', 'scheduled', 'running'])->count();
        $pending = Booking::whereIn('status', ['payment_pending', 'media_review_pending', 'pending_approval'])->count();
        $completed = Booking::where('status', Booking::STATUS_COMPLETED)->count();
        $cancelled = Booking::where('status', Booking::STATUS_CANCELLED)->count();

        $revenueToday = (float) Booking::whereDate('created_at', $todayStart->format('Y-m-d'))
            ->where('status', '!=', Booking::STATUS_CANCELLED)
            ->sum('final_amount');

        // ── Reward KPIs ──
        $totalRewardPointsIssued = (float) \App\Models\RewardAccount::sum('lifetime_earned');
        $totalRewardPointsRedeemed = (float) \App\Models\RewardAccount::sum('lifetime_redeemed');
        $totalRewardPointsExpired = (float) \App\Models\RewardAccount::sum('lifetime_expired');
        $outstandingRewardLiability = (float) \App\Models\RewardAccount::sum('balance');
        $totalRewardAccounts = \App\Models\RewardAccount::count();
        $todayRewardPointsIssued = (float) \App\Models\RewardTransaction::whereDate('created_at', $todayStart->format('Y-m-d'))
            ->whereIn('type', ['earn', 'admin_bonus'])
            ->where('points', '>', 0)
            ->sum('points');
        $todayRewardPointsRedeemed = (float) \App\Models\RewardTransaction::whereDate('created_at', $todayStart->format('Y-m-d'))
            ->where('type', 'redeem')
            ->sum(\Illuminate\Support\Facades\DB::raw('ABS(points)'));
        $rewardBookingCount = \App\Models\Booking::where('reward_discount_amount', '>', 0)->count();
        $totalBookingsWithRewards = \App\Models\Booking::where(function ($q) {
            $q->where('reward_points_earned', '>', 0)
              ->orWhere('reward_points_redeemed', '>', 0);
        })->count();

        // ── Contact Enquiry Stats ──
        $newEnquiries = ContactEnquiry::where('status', 'new')->count();
        $openEnquiries = ContactEnquiry::whereIn('status', ['new', 'contacted', 'in_progress'])->count();
        $inProgressEnquiries = ContactEnquiry::where('status', 'in_progress')->count();
        $closedEnquiries = ContactEnquiry::where('status', 'closed')->count();
        $recentEnquiries = ContactEnquiry::latest()->take(5)->get(['id', 'name', 'email', 'status', 'created_at']);

        // ── Campaign Stats ──
        $activeCampaigns = Booking::whereIn('status', ['approved', 'scheduled', 'running'])
            ->where(function ($q) {
                $q->whereHas('bookingScreens')
                  ->orWhere('total_screens', '>', 1);
            })
            ->count();

        $endingSoon = Booking::whereIn('status', ['approved', 'scheduled', 'running'])
            ->where('end_time', '>=', $now)
            ->where('end_time', '<=', $now->copy()->addDays(2))
            ->count();

        // ── Weekly Utilization Chart Data ──
        $utilizationData = $this->screenAnalytics->utilizationDistribution(
            $weekStart->format('Y-m-d'),
            $now->format('Y-m-d')
        );

        // Map to day-of-week labels
        $dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $utilizationChart = [];
        foreach ($utilizationData as $entry) {
            $dayOfWeek = Carbon::parse($entry['date'])->dayOfWeekIso - 1; // 0 = Mon
            $utilizationChart[] = [
                'day' => $dayLabels[$dayOfWeek] ?? $entry['date'],
                'rate' => $entry['utilization'],
                'date' => $entry['date'],
            ];
        }

        // ── Booking Distribution (for pie chart) ──
        $bookingDistribution = [
            ['name' => 'Confirmed', 'value' => $confirmed, 'color' => '#4d50d9'],
            ['name' => 'Pending', 'value' => $pending, 'color' => '#7476ff'],
            ['name' => 'Completed', 'value' => $completed, 'color' => '#30339b'],
            ['name' => 'Cancelled', 'value' => $cancelled, 'color' => '#9e9fff'],
        ];

        // ── Category Distribution (bookings by category) ──
        $categoryDistribution = DB::table('bookings')
            ->whereNotNull('advertisement_category_name')
            ->where('status', '!=', Booking::STATUS_CANCELLED)
            ->select('advertisement_category_name as name', DB::raw('COUNT(*) as value'))
            ->groupBy('advertisement_category_name')
            ->orderByDesc('value')
            ->get()
            ->map(function ($item, $index) {
                $colors = ['#4d50d9', '#7476ff', '#30339b', '#9e9fff', '#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#8B5CF6', '#EC4899'];
                return [
                    'name' => $item->name,
                    'value' => (int) $item->value,
                    'color' => $colors[$index % count($colors)],
                ];
            });

        // ── Recent Bookings ──
        $recentBookings = Booking::with('screen:id,name')
            ->orderByDesc('created_at')
            ->take(6)
            ->get()
            ->map(function ($b) {
                return [
                    'id' => $b->id,
                    'reference' => '#BOK-' . str_pad($b->id, 4, '0', STR_PAD_LEFT),
                    'advertiser' => $b->advertiser_name ?: $b->title,
                    'screen' => $b->screen?->name ?? ($b->total_screens > 1 ? "{$b->total_screens} screens" : 'N/A'),
                    'date' => $b->created_at->format('M d, h:i A'),
                    'status' => $b->status,
                    'amount' => (float) ($b->final_amount ?: $b->price ?: 0),
                    'is_campaign' => $b->total_screens > 1 || $b->bookingScreens()->count() > 0,
                ];
            });

        // ── Top Performing Screens ──
        $topScreensData = $this->screenAnalytics->screenPerformance(
            $monthStart->format('Y-m-d'),
            $monthEnd->format('Y-m-d')
        );

        $topScreens = collect($topScreensData)
            ->sortByDesc('booking_count')
            ->take(5)
            ->values()
            ->map(function ($s, $i) {
                return [
                    'rank' => $i + 1,
                    'name' => $s['screen_name'],
                    'utilization' => $s['occupancy_pct'],
                    'revenue' => $s['revenue'],
                    'bookings' => $s['booking_count'],
                ];
            });

        // ── Alerts ──
        $pendingApprovals = Booking::whereIn('status', ['media_review_pending', 'pending_approval'])->count();
        $uploadErrors = 0; // Static for now — could come from media table

        $alerts = [
            [
                'type' => 'offline',
                'label' => 'Screens Offline',
                'count' => $offlineScreens,
                'detail' => "{$offlineScreens} screens need attention",
                'severity' => 'danger',
            ],
            [
                'type' => 'expiring',
                'label' => 'Campaigns Ending Soon',
                'count' => $endingSoon,
                'detail' => "{$endingSoon} campaigns expire within 48h",
                'severity' => 'warning',
            ],
            [
                'type' => 'pending',
                'label' => 'Pending Approvals',
                'count' => $pendingApprovals,
                'detail' => "{$pendingApprovals} bookings awaiting approval",
                'severity' => 'warning',
            ],
            [
                'type' => 'failed',
                'label' => 'Pending Media Review',
                'count' => Booking::where('status', Booking::STATUS_MEDIA_REVIEW_PENDING)->count(),
                'detail' => 'Media files pending review',
                'severity' => 'warning',
            ],
        ];

        // ── Screen Provider KPIs ──
        $totalScreenProviders = ScreenProvider::active()->count();
        $providerOwnedScreens = Screen::where('owner_type', Screen::OWNER_TYPE_PROVIDER)->count();

        $providerRevenue = (float) BookingScreen::where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                    Booking::STATUS_COMPLETED,
                ]);
            })
            ->sum('provider_share');

        $platformRevenueFromProviders = (float) BookingScreen::where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                    Booking::STATUS_COMPLETED,
                ]);
            })
            ->sum('platform_share');

        $pendingSettlements = (float) BookingScreen::where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->where('settlement_status', 'pending')
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                    Booking::STATUS_COMPLETED,
                ]);
            })
            ->sum('provider_share');

        $outstandingLiability = $pendingSettlements;

        $settlementsThisMonth = ScreenProviderSettlement::whereMonth('created_at', $now->month)
            ->whereYear('created_at', $now->year)
            ->count();

        // ── KPIs ──
        $rewardKpis = [
            [
                'title' => 'Points Issued',
                'value' => $totalRewardPointsIssued,
                'meta' => number_format($totalRewardAccounts) . ' accounts',
                'icon' => 'award',
                'to' => '/rewards',
            ],
            [
                'title' => 'Points Redeemed',
                'value' => $totalRewardPointsRedeemed,
                'meta' => number_format($todayRewardPointsRedeemed) . ' today',
                'icon' => 'award',
                'to' => '/rewards',
            ],
            [
                'title' => 'Points Expired',
                'value' => $totalRewardPointsExpired,
                'meta' => 'Total expired points',
                'icon' => 'clock',
                'to' => '/rewards',
            ],
            [
                'title' => 'Outstanding Liability',
                'value' => $outstandingRewardLiability,
                'meta' => 'Active point balances',
                'icon' => 'income',
                'to' => '/rewards',
            ],
            [
                'title' => 'Today Points Issued',
                'value' => $todayRewardPointsIssued,
                'meta' => number_format($totalBookingsWithRewards) . ' bookings with rewards',
                'icon' => 'activity',
                'to' => '/rewards',
            ],
            [
                'title' => 'Today Points Redeemed',
                'value' => $todayRewardPointsRedeemed,
                'meta' => number_format($rewardBookingCount) . ' bookings used points',
                'icon' => 'activity',
                'to' => '/rewards',
            ],
        ];

        $kpis = [
            [
                'title' => 'Active Screens',
                'value' => $activeScreensCount,
                'meta' => "{$utilizationRate}% utilization",
                'icon' => 'activity',
                'to' => '/screens',
            ],
            [
                'title' => 'Available Screens',
                'value' => $availableScreens,
                'meta' => 'Ready for bookings',
                'icon' => 'monitor',
                'to' => '/screens',
            ],
            [
                'title' => 'Sold Out Screens',
                'value' => $soldOutScreens,
                'meta' => 'Temporarily booked',
                'icon' => 'tag',
                'to' => '/screens',
            ],
            [
                'title' => "Today's Bookings",
                'value' => $bookingsToday,
                'meta' => ($bookingsToday > 0 ? '+' : '') . $bookingsToday . ' today',
                'icon' => 'calendar',
                'to' => '/bookings',
            ],
            [
                'title' => 'Revenue Today',
                'value' => $revenueToday,
                'meta' => 'Today\'s accrued revenue',
                'icon' => 'income',
                'to' => '/bookings',
            ],
            [
                'title' => 'Utilization Rate',
                'value' => $utilizationRate,
                'meta' => "{$activeScreensCount}/{$totalScreens} screens active",
                'icon' => 'trending',
                'to' => '/reports',
            ],
        ];

        // Provider KPI cards
        $providerKpis = [
            [
                'title' => 'Total Screen Providers',
                'value' => $totalScreenProviders,
                'meta' => 'Active providers',
                'icon' => 'users',
                'to' => '/screen-providers',
            ],
            [
                'title' => 'Provider-Owned Screens',
                'value' => $providerOwnedScreens,
                'meta' => 'Screens across all providers',
                'icon' => 'monitor',
                'to' => '/screens',
            ],
            [
                'title' => 'Provider Revenue',
                'value' => $providerRevenue,
                'meta' => 'Total earned by providers',
                'icon' => 'income',
                'to' => '/screen-providers',
            ],
            [
                'title' => 'Platform Revenue',
                'value' => $platformRevenueFromProviders,
                'meta' => 'Illuvision share from provider screens',
                'icon' => 'income',
                'to' => '/reports',
            ],
            [
                'title' => 'Pending Settlements',
                'value' => $pendingSettlements,
                'meta' => 'Awaiting settlement',
                'icon' => 'clock',
                'to' => '/settlements',
            ],
            [
                'title' => 'Outstanding Liability',
                'value' => $outstandingLiability,
                'meta' => 'Total unpaid to providers',
                'icon' => 'clock',
                'to' => '/settlements',
            ],
            [
                'title' => 'Settlements This Month',
                'value' => $settlementsThisMonth,
                'meta' => 'Generated this month',
                'icon' => 'activity',
                'to' => '/settlements',
            ],
        ];

        return $this->successResponse([
            'kpis' => $kpis,
            'provider_kpis' => $providerKpis,
            'reward_kpis' => $rewardKpis,
            'screens' => [
                'total' => $totalScreens,
                'active' => $activeScreensCount,
                'offline' => $offlineScreens,
                'available' => $availableScreens,
                'sold_out' => $soldOutScreens,
                'maintenance' => $maintenanceScreens,
                'utilizationRate' => $utilizationRate,
            ],
            'bookings' => [
                'today' => $bookingsToday,
                'confirmed' => $confirmed,
                'pending' => $pending,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'revenueToday' => $revenueToday,
            ],
            'campaigns' => [
                'active' => $activeCampaigns,
                'endingSoon' => $endingSoon,
            ],
            'utilizationChart' => $utilizationChart,
            'bookingDistribution' => $bookingDistribution,
            'categoryDistribution' => $categoryDistribution,
            'recentBookings' => $recentBookings,
            'topScreens' => $topScreens,
            'alerts' => $alerts,
            // Contact Enquiry Widgets
            'contact_stats' => [
                'new_enquiries' => $newEnquiries,
                'open_enquiries' => $openEnquiries,
                'in_progress_enquiries' => $inProgressEnquiries,
                'closed_enquiries' => $closedEnquiries,
            ],
            'recent_enquiries' => $recentEnquiries,
        ], 'Dashboard data fetched successfully');
    }
}
