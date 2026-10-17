<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\Screen;
use App\Models\ScreenProvider;
use App\Models\ScreenProviderSettlement;
use App\Services\RevenueDistributionService;
use App\Services\ScreenProviderService;
use App\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScreenProviderPortalController extends Controller
{
    public function __construct(
        private ScreenProviderService $screenProviderService,
        private RevenueDistributionService $revenueDistributionService,
        private SettlementService $settlementService,
    ) {
    }

    /**
     * Get screen provider dashboard overview.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied or record not found.', 403);
        }

        // 1. Screens count
        $ownedScreensCount = Screen::where('screen_provider_id', $provider->id)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->count();

        // 2. Active campaigns (running, scheduled, approved)
        $activeCampaignsCount = BookingScreen::where('screen_provider_id', $provider->id)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                ]);
            })
            ->distinct('booking_id')
            ->count('booking_id');

        // 3. Gross revenue (from booking screens)
        $totalGrossRevenue = (float) BookingScreen::where('screen_provider_id', $provider->id)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                    Booking::STATUS_COMPLETED,
                ]);
            })
            ->sum('gross_revenue');

        // 4. Provider Share (My Revenue)
        $totalProviderRevenue = (float) BookingScreen::where('screen_provider_id', $provider->id)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                    Booking::STATUS_COMPLETED,
                ]);
            })
            ->sum('provider_share');

        // 5. Settled Amount (paid settlements)
        $settledAmount = (float) ScreenProviderSettlement::where('screen_provider_id', $provider->id)
            ->where('status', ScreenProviderSettlement::STATUS_PAID)
            ->sum('provider_revenue');

        // 6. Outstanding Balance
        $outstandingBalance = $totalProviderRevenue - $settledAmount;

        // 7. Pending Settlement (unsettled booking screens share)
        $pendingSettlement = (float) BookingScreen::where('screen_provider_id', $provider->id)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
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

        // 8. Recent Campaigns (grouped by booking)
        $recentCampaigns = BookingScreen::where('booking_screens.screen_provider_id', $provider->id)
            ->where('booking_screens.owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->where('bookings.status', '!=', 'payment_pending')
            ->select(
                'booking_screens.booking_id',
                'bookings.campaign_title',
                'bookings.advertiser_name',
                'bookings.start_time',
                'bookings.end_time',
                'bookings.status as booking_status',
                DB::raw('SUM(booking_screens.provider_share) as provider_share')
            )
            ->groupBy(
                'booking_screens.booking_id',
                'bookings.campaign_title',
                'bookings.advertiser_name',
                'bookings.start_time',
                'bookings.end_time',
                'bookings.status'
            )
            ->orderBy('bookings.created_at', 'desc')
            ->take(5)
            ->get();

        // 9. Recent Settlements
        $recentSettlements = ScreenProviderSettlement::where('screen_provider_id', $provider->id)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($s) {
                $s->status_label = ScreenProviderSettlement::statuses()[$s->status] ?? $s->status;
                return $s;
            });

        // 10. Recent Activities (reusing existing timeline)
        $recentActivities = $this->screenProviderService->getActivityTimeline($provider, 10);

        return $this->successResponse([
            'summary' => [
                'owned_screens_count' => $ownedScreensCount,
                'active_campaigns' => $activeCampaignsCount,
                'total_gross_revenue' => $totalGrossRevenue,
                'total_provider_revenue' => $totalProviderRevenue,
                'pending_settlement' => $pendingSettlement,
                'settled_revenue' => $settledAmount,
                'outstanding_balance' => $outstandingBalance,
            ],
            'recent_campaigns' => $recentCampaigns,
            'recent_settlements' => $recentSettlements,
            'recent_activities' => $recentActivities,
        ], 'Dashboard data retrieved.');
    }

    /**
     * Get owned screens for the authenticated screen provider.
     */
    public function screens(Request $request): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied or record not found.', 403);
        }

        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);
        $countryId = $request->input('country_id');
        $cityId = $request->input('city_id');
        $screenType = $request->input('screen_type');
        $assetType = $request->input('asset_type');
        $status = $request->input('status');
        $bookingStatus = $request->input('booking_status');

        // Authoritative server-side aggregate inventory stats for the provider
        $totalScreens = Screen::where('screen_provider_id', $provider->id)->where('owner_type', Screen::OWNER_TYPE_PROVIDER)->count();
        $activeScreens = Screen::where('screen_provider_id', $provider->id)->where('owner_type', Screen::OWNER_TYPE_PROVIDER)->where('status', 'active')->count();
        $fixedScreens = Screen::where('screen_provider_id', $provider->id)->where('owner_type', Screen::OWNER_TYPE_PROVIDER)->where(function($q) {
            $q->where('asset_type', 'fixed')->orWhereNull('asset_type');
        })->count();
        $mobileScreens = Screen::where('screen_provider_id', $provider->id)->where('owner_type', Screen::OWNER_TYPE_PROVIDER)->where(function($q) {
            $q->where('asset_type', 'mobile')->orWhere('asset_type', 'vehicle');
        })->count();

        $totalRevenue = (float) BookingScreen::whereHas('screen', function ($q) use ($provider) {
            $q->where('screen_provider_id', $provider->id)->where('owner_type', Screen::OWNER_TYPE_PROVIDER);
        })->whereHas('booking', function ($q) {
            $q->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED]);
        })->sum('provider_share');

        $screens = Screen::where('screen_provider_id', $provider->id)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->with(['city', 'country', 'pricingPlan', 'screenProvider', 'screenCategory', 'coverageCities', 'coverageStates'])
            ->when($search, function ($query) use ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('screen_code', 'like', "%{$search}%")
                      ->orWhere('location_name', 'like', "%{$search}%");
                });
            })
            ->when($assetType, function ($query) use ($assetType) {
                if ($assetType === 'mobile' || $assetType === 'vehicle') {
                    $query->where(function($q) { $q->where('asset_type', 'mobile')->orWhere('asset_type', 'vehicle'); });
                } elseif ($assetType === 'fixed') {
                    $query->where(function($q) { $q->where('asset_type', 'fixed')->orWhereNull('asset_type'); });
                } else {
                    $query->where('asset_type', $assetType);
                }
            })
            ->when($countryId, function ($query) use ($countryId) {
                $query->where('country_id', $countryId);
            })
            ->when($cityId, function ($query) use ($cityId) {
                $query->where('city_id', $cityId);
            })
            ->when($screenType, function ($query) use ($screenType) {
                $query->where('screen_type', $screenType);
            })
            ->when($status, function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($bookingStatus, function ($query) use ($bookingStatus) {
                $query->where('booking_status', $bookingStatus);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $screens->getCollection()->transform(function ($screen) {
            // Campaign count
            $screen->campaign_count = BookingScreen::where('screen_id', $screen->id)
                ->whereHas('booking', function ($q) {
                    $q->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED]);
                })
                ->distinct('booking_id')
                ->count('booking_id');

            // Gross revenue
            $screen->gross_revenue = (float) BookingScreen::where('screen_id', $screen->id)
                ->whereHas('booking', function ($q) {
                    $q->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED]);
                })
                ->sum('gross_revenue');

            // Provider share
            $screen->provider_revenue = (float) BookingScreen::where('screen_id', $screen->id)
                ->whereHas('booking', function ($q) {
                    $q->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED]);
                })
                ->sum('provider_share');

            return $screen;
        });

        return response()->json([
            'success' => true,
            'message' => 'Screens retrieved successfully.',
            'data' => $screens->items(),
            'meta' => [
                'current_page' => $screens->currentPage(),
                'last_page' => $screens->lastPage(),
                'per_page' => $screens->perPage(),
                'total' => $screens->total(),
                'stats' => [
                    'total_screens' => $totalScreens,
                    'active_screens' => $activeScreens,
                    'fixed_screens' => $fixedScreens,
                    'mobile_screens' => $mobileScreens,
                    'total_revenue' => $totalRevenue,
                ]
            ],
            'links' => [
                'first' => $screens->url(1),
                'last' => $screens->url($screens->lastPage()),
                'prev' => $screens->previousPageUrl(),
                'next' => $screens->nextPageUrl(),
            ]
        ]);
    }

    /**
     * Store a newly created screen for the authenticated screen provider.
     */
    public function storeScreen(Request $request): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied or record not found.', 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'screen_code' => 'required|string|max:50|unique:screens,screen_code',
            'screen_type' => 'nullable|string|max:255',
            'screen_category_id' => 'nullable|exists:screen_categories,id',
            'asset_type' => 'nullable|string|max:50',
            'pricing_unit' => 'nullable|string|max:50',
            'fleet_size' => 'nullable|integer|min:1',
            'working_hours_pd' => 'nullable|integer|min:1|max:24',
            'travel_distance_km_pd' => 'nullable|integer|min:0',
            'display_specs' => 'nullable|string|max:255',
            'asset_metadata' => 'nullable|array',
            'country_id' => 'required|exists:countries,id',
            'state_id' => 'nullable|exists:states,id',
            'city_id' => 'required|exists:cities,id',
            'location_name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive,maintenance',
            'orientation' => 'nullable|in:landscape,portrait,square',
            'resolution' => 'nullable|string|max:50',
            'operating_hours' => 'nullable|string|max:50',
            'thumbnail' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'coverage_state_ids' => 'nullable|array',
            'coverage_state_ids.*' => 'exists:states,id',
            'coverage_city_ids' => 'nullable|array',
            'coverage_city_ids.*' => 'exists:cities,id',
        ]);

        $coverageStateIds = $validated['coverage_state_ids'] ?? [];
        $coverageCityIds = $validated['coverage_city_ids'] ?? [];
        unset($validated['coverage_state_ids'], $validated['coverage_city_ids']);

        // Automatically assign owner type, screen provider, and set pricing plan to null (locked)
        $validated['owner_type'] = Screen::OWNER_TYPE_PROVIDER;
        $validated['screen_provider_id'] = $provider->id;
        $validated['pricing_plan_id'] = null;

        $screen = Screen::create($validated);

        if (!empty($coverageStateIds)) {
            $screen->coverageStates()->sync($coverageStateIds);
        }
        if (!empty($coverageCityIds)) {
            $screen->coverageCities()->sync($coverageCityIds);
        }

        // Log provider timeline activity
        $this->screenProviderService->addActivity(
            $provider,
            'screen_created',
            "Screen \"{$screen->name}\" was created by provider.",
            $request->user(),
            ['screen_id' => $screen->id]
        );

        // Log global activity log
        \App\Models\ActivityLog::log(
            'screen_created_by_provider',
            Screen::class,
            $screen->id,
            "Screen \"{$screen->name}\" created by screen provider {$provider->provider_name}.",
            null,
            $validated
        );

        return $this->successResponse($screen->load(['city', 'country', 'pricingPlan', 'screenProvider', 'screenCategory', 'coverageCities', 'coverageStates']), 'Screen created successfully.', 201);
    }

    /**
     * Update an owned screen's permitted fields.
     */
    public function updateScreen(Request $request, Screen $screen): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider || $screen->screen_provider_id !== $provider->id) {
            return $this->errorResponse('Unauthorized access to screen update.', 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'screen_type' => 'nullable|string|max:255',
            'screen_category_id' => 'nullable|exists:screen_categories,id',
            'asset_type' => 'nullable|string|max:50',
            'pricing_unit' => 'nullable|string|max:50',
            'fleet_size' => 'nullable|integer|min:1',
            'working_hours_pd' => 'nullable|integer|min:1|max:24',
            'travel_distance_km_pd' => 'nullable|integer|min:0',
            'display_specs' => 'nullable|string|max:255',
            'asset_metadata' => 'nullable|array',
            'country_id' => 'sometimes|required|exists:countries,id',
            'state_id' => 'nullable|exists:states,id',
            'city_id' => 'sometimes|required|exists:cities,id',
            'location_name' => 'sometimes|required|string|max:255',
            'status' => 'sometimes|required|in:active,inactive,maintenance',
            'orientation' => 'nullable|in:landscape,portrait,square',
            'resolution' => 'nullable|string|max:50',
            'operating_hours' => 'nullable|string|max:50',
            'thumbnail' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'coverage_state_ids' => 'nullable|array',
            'coverage_state_ids.*' => 'exists:states,id',
            'coverage_city_ids' => 'nullable|array',
            'coverage_city_ids.*' => 'exists:cities,id',
        ]);

        // Sync coverage states if provided
        if (array_key_exists('coverage_state_ids', $validated)) {
            $screen->coverageStates()->sync($validated['coverage_state_ids'] ?? []);
            unset($validated['coverage_state_ids']);
        }

        // Sync coverage cities if provided
        if (array_key_exists('coverage_city_ids', $validated)) {
            $screen->coverageCities()->sync($validated['coverage_city_ids'] ?? []);
            unset($validated['coverage_city_ids']);
        }

        // Delete old thumbnail if new one provided
        if (!empty($validated['thumbnail']) && $screen->thumbnail && $validated['thumbnail'] !== $screen->thumbnail) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($screen->thumbnail);
        }

        $screen->update($validated);

        // Log provider timeline activity
        $this->screenProviderService->addActivity(
            $provider,
            'screen_updated',
            "Screen \"{$screen->name}\" was updated by provider.",
            $request->user(),
            ['screen_id' => $screen->id]
        );

        // Log global activity log
        \App\Models\ActivityLog::log(
            'screen_updated_by_provider',
            Screen::class,
            $screen->id,
            "Screen \"{$screen->name}\" updated by screen provider {$provider->provider_name}.",
            null,
            $validated
        );

        return $this->successResponse($screen->load(['city', 'country', 'pricingPlan', 'screenProvider', 'screenCategory', 'coverageCities', 'coverageStates']), 'Screen updated successfully.');
    }

    /**
     * Get details of an owned screen.
     */
    public function screenDetails(Request $request, Screen $screen): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider || $screen->screen_provider_id !== $provider->id) {
            return $this->errorResponse('Unauthorized access to screen details.', 403);
        }

        $screen->load(['city', 'country', 'pricingPlan', 'screenProvider', 'screenCategory', 'coverageCities', 'coverageStates']);

        // Revenue summary
        $grossRevenue = (float) BookingScreen::where('screen_id', $screen->id)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED]);
            })
            ->sum('gross_revenue');

        $providerShare = (float) BookingScreen::where('screen_id', $screen->id)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED]);
            })
            ->sum('provider_share');

        $platformShare = $grossRevenue - $providerShare;

        // Campaign count
        $campaignCount = BookingScreen::where('screen_id', $screen->id)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED]);
            })
            ->distinct('booking_id')
            ->count('booking_id');

        // Recent campaigns
        $recentCampaigns = BookingScreen::where('booking_screens.screen_id', $screen->id)
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereIn('bookings.status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED])
            ->select(
                'booking_screens.booking_id',
                'bookings.campaign_title',
                'bookings.advertiser_name',
                'bookings.start_time',
                'bookings.end_time',
                'bookings.status as booking_status',
                'booking_screens.provider_share',
                'booking_screens.settlement_status'
            )
            ->orderBy('bookings.created_at', 'desc')
            ->take(5)
            ->get();

        // Booked Dates for Calendar Visualization
        $bookedDates = BookingScreen::where('booking_screens.screen_id', $screen->id)
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->whereIn('bookings.status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED])
            ->select('bookings.start_time', 'bookings.end_time', 'bookings.status')
            ->get()
            ->map(function ($b) {
                return [
                    'start' => $b->start_time->format('Y-m-d'),
                    'end' => $b->end_time->format('Y-m-d'),
                    'status' => $b->status,
                ];
            });

        return $this->successResponse([
            'screen' => $screen,
            'revenue_summary' => [
                'gross_revenue' => $grossRevenue,
                'provider_share' => $providerShare,
                'platform_share' => $platformShare,
            ],
            'campaign_count' => $campaignCount,
            'recent_campaigns' => $recentCampaigns,
            'booked_dates' => $bookedDates,
        ], 'Screen details retrieved successfully.');
    }

    /**
     * Get campaign history running on provider's screens.
     */
    public function campaigns(Request $request): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied or record not found.', 403);
        }

        $perPage = $request->input('per_page', 15);

        $bookingsQuery = Booking::whereHas('bookingScreens', function ($q) use ($provider) {
            $q->where('screen_provider_id', $provider->id);
        })
        ->with(['bookingScreens' => function ($q) use ($provider) {
            $q->where('screen_provider_id', $provider->id);
        }, 'bookingScreens.screen']);

        $bookings = $bookingsQuery->orderBy('created_at', 'desc')->paginate($perPage);

        $bookings->getCollection()->transform(function ($booking) use ($provider) {
            $providerScreens = $booking->bookingScreens->where('screen_provider_id', $provider->id);

            return [
                'id' => $booking->id,
                'booking_number' => '#BOK-' . str_pad($booking->id, 4, '0', STR_PAD_LEFT),
                'campaign_title' => $booking->campaign_title ?: $booking->title,
                'advertiser_name' => $booking->advertiser_name,
                'start_time' => $booking->start_time->format('Y-m-d H:i:s'),
                'end_time' => $booking->end_time->format('Y-m-d H:i:s'),
                'duration_minutes' => $booking->duration_minutes,
                'duration_days' => $booking->duration_days,
                'duration_label' => $booking->duration_label,
                'duration' => $booking->duration_label,
                'screens_count' => $providerScreens->count(),
                'provider_revenue' => (float) $providerScreens->sum('provider_share'),
                'status' => $booking->status,
            ];
        });

        return $this->paginatedResponse($bookings, 'Campaign history retrieved successfully.');
    }

    /**
     * Get detailed read-only campaign information.
     */
    public function campaignDetails(Request $request, int $id): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied.', 403);
        }

        $booking = Booking::with(['bookingScreens' => function ($q) use ($provider) {
            $q->where('screen_provider_id', $provider->id);
        }, 'bookingScreens.screen'])->findOrFail($id);

        $hasOwnership = $booking->bookingScreens->isNotEmpty();
        if (!$hasOwnership) {
            return $this->errorResponse('Unauthorized access to campaign details.', 403);
        }

        // Format dates and details
        $details = [
            'id' => $booking->id,
            'booking_number' => '#BOK-' . str_pad($booking->id, 4, '0', STR_PAD_LEFT),
            'campaign_title' => $booking->campaign_title ?: $booking->title,
            'advertiser_name' => $booking->advertiser_name,
            'advertiser_email' => $booking->advertiser_email,
            'advertiser_phone' => $booking->advertiser_phone,
            'start_time' => $booking->start_time->format('Y-m-d H:i:s'),
            'end_time' => $booking->end_time->format('Y-m-d H:i:s'),
            'duration_minutes' => $booking->duration_minutes,
            'duration_days' => $booking->duration_days,
            'duration_label' => $booking->duration_label,
            'duration' => $booking->duration_label,
            'status' => $booking->status,
            'notes' => $booking->notes,
            'screens' => $booking->bookingScreens->map(function ($bs) {
                return [
                    'screen_name' => $bs->screen?->name,
                    'location' => $bs->screen?->location_name,
                    'provider_percentage' => (float) ($bs->screen_provider_percentage ?? 90.00),
                    'screen_provider_percentage' => (float) ($bs->screen_provider_percentage ?? 90.00),
                    'provider_share' => (float) $bs->provider_share,
                    'gross_revenue' => (float) $bs->gross_revenue,
                    'settlement_status' => $bs->settlement_status,
                ];
            }),
            'total_provider_revenue' => (float) $booking->bookingScreens->sum('provider_share'),
        ];

        return $this->successResponse($details, 'Campaign details retrieved.');
    }

    /**
     * Get revenue stats and trends.
     */
    public function revenue(Request $request): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied or record not found.', 403);
        }

        $revenueStats = $this->settlementService->getProviderRevenueStatsWithSettlements($provider->id);
        $screenRevenue = $this->revenueDistributionService->getProviderScreenRevenue($provider->id);

        // Monthly breakdown
        $monthlyRevenue = BookingScreen::where('screen_provider_id', $provider->id)
            ->where('owner_type', Screen::OWNER_TYPE_PROVIDER)
            ->whereHas('booking', function ($q) {
                $q->whereIn('status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_RUNNING, Booking::STATUS_COMPLETED]);
            })
            ->selectRaw('
                DATE_FORMAT(booking_screens.created_at, "%Y-%m") as month,
                SUM(gross_revenue) as gross_revenue,
                SUM(platform_share) as platform_share,
                SUM(provider_share) as provider_share
            ')
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->get();

        return $this->successResponse([
            'stats' => $revenueStats,
            'by_screen' => $screenRevenue,
            'by_month' => $monthlyRevenue,
        ], 'Revenue analytics retrieved.');
    }

    /**
     * Get settlements for the screen provider.
     */
    public function settlements(Request $request): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied or record not found.', 403);
        }

        $perPage = $request->input('per_page', 10);
        $settlements = $this->settlementService->getProviderSettlements($provider->id, ['per_page' => $perPage]);

        $settlements->getCollection()->transform(function ($s) {
            $s->status_label = ScreenProviderSettlement::statuses()[$s->status] ?? $s->status;
            return $s;
        });

        return $this->paginatedResponse($settlements, 'Settlement history retrieved.');
    }

    /**
     * Get notifications logged for the screen provider's email or user id.
     */
    public function notifications(Request $request): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied.', 403);
        }

        $perPage = $request->input('per_page', 15);
        $notifications = \App\Models\NotificationLog::where(function ($q) use ($provider) {
            $q->where('recipient_email', $provider->email);
            if ($provider->user_id) {
                $q->orWhere('recipient_id', $provider->user_id);
            }
        })
        ->orderBy('created_at', 'desc')
        ->paginate($perPage);

        return $this->paginatedResponse($notifications, 'Notification logs retrieved.');
    }

    /**
     * Submit a support enquiry and view enquiry history.
     */
    public function submitEnquiry(Request $request): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied.', 403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        $enquiry = \App\Models\ContactEnquiry::create([
            'name' => $provider->contact_person ?: $provider->provider_name,
            'email' => $provider->email,
            'phone' => $provider->phone,
            'message' => $validated['message'],
            'status' => 'new',
            'screen_provider_id' => $provider->id,
        ]);

        // Trigger support submitted event if any exists, otherwise just log to activities
        $this->screenProviderService->addActivity(
            $provider,
            'support_enquiry_submitted',
            'Submitted a support enquiry: "' . substr($validated['message'], 0, 50) . '..."',
            $request->user()
        );

        return $this->successResponse($enquiry, 'Support enquiry submitted successfully.', 201);
    }

    public function enquiryHistory(Request $request): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied.', 403);
        }

        $perPage = $request->input('per_page', 10);
        $enquiries = \App\Models\ContactEnquiry::where('screen_provider_id', $provider->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->paginatedResponse($enquiries, 'Support enquiry history retrieved.');
    }

    /**
     * Update screen provider profile and password.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $provider = $this->getAuthenticatedProvider($request);
        if (!$provider) {
            return $this->errorResponse('Screen provider access denied or record not found.', 403);
        }

        $validated = $request->validate([
            'contact_person' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:20',
            'business_name' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $providerData = collect($validated)->only(['contact_person', 'phone', 'business_name'])->toArray();
        $provider->update($providerData);

        $user = $request->user();
        $userData = [];
        if (isset($validated['contact_person'])) {
            $userData['name'] = $validated['contact_person'];
        }
        if (isset($validated['phone'])) {
            $userData['phone'] = $validated['phone'];
        }
        if (!empty($validated['password'])) {
            $userData['password'] = \Illuminate\Support\Facades\Hash::make($validated['password']);
        }
        if (!empty($userData)) {
            $user->update($userData);
        }

        // Log profile change activity
        $this->screenProviderService->addActivity(
            $provider,
            'profile_updated',
            'Profile contact information updated by ' . $user->name,
            $user
        );

        return $this->successResponse([
            'provider' => $provider->fresh(),
            'user' => new \App\Http\Resources\UserResource($user->fresh()),
        ], 'Profile updated successfully.');
    }

    /**
     * Setup password using token.
     */
    public function setupPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $record = DB::table('password_setup_tokens')
            ->where('email', $validated['email'])
            ->where('token', $validated['token'])
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return $this->errorResponse('Invalid or expired token.', 400);
        }

        DB::transaction(function () use ($record, $validated) {
            // Update user password
            $user = \App\Models\User::where('email', $validated['email'])->first();
            if ($user) {
                $user->update(['password' => \Illuminate\Support\Facades\Hash::make($validated['password'])]);
            }

            // Mark token as used
            DB::table('password_setup_tokens')
                ->where('id', $record->id)
                ->update([
                    'used' => true,
                    'used_at' => now(),
                ]);
        });

        return $this->successResponse(null, 'Password has been set successfully. You can now login.');
    }

    /**
     * Get the authenticated provider from the request user.
     */
    private function getAuthenticatedProvider(Request $request): ?ScreenProvider
    {
        $user = $request->user();
        if (!$user || $user->role !== 'screen_provider') {
            return null;
        }

        return ScreenProvider::where('user_id', $user->id)
            ->where('status', ScreenProvider::STATUS_APPROVED)
            ->first();
    }
}
