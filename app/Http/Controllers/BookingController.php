<?php

namespace App\Http\Controllers;

use App\Application\Booking\Exceptions\BookingCreationException;
use App\Application\Booking\Queries\CheckBookingAvailabilityQuery;
use App\Application\Booking\UseCases\CreateCampaignBookingUseCase;
use App\Application\Booking\UseCases\RescheduleBookingUseCase;
use App\Support\Events\Notifications\BookingApproved;
use App\Support\Events\Notifications\BookingCreated;
use App\Support\Events\Notifications\BookingRejected;
use App\Support\Events\Notifications\MediaApproved;
use App\Support\Events\Notifications\MediaRejected;
use App\Support\Events\Notifications\MediaUploaded;
use App\Support\Events\Notifications\PaymentReceived;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingMedia;
use App\Models\BookingScreen;
use App\Models\Payment;
use App\Models\Screen;
use App\Services\BookingCapacityService;
use App\Services\CampaignPricingService;
use App\Services\InventoryService;
use App\Services\MediaService;
use App\Services\RevenueSplitResolverService;
use App\Support\Configs\BookingConfig;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    protected MediaService $mediaService;

    protected BookingCapacityService $capacityService;

    protected CampaignPricingService $campaignPricingService;

    protected BookingConfig $bookingConfig;

    public function __construct(
        MediaService $mediaService,
        BookingCapacityService $capacityService,
        CampaignPricingService $campaignPricingService,
        BookingConfig $bookingConfig,
        protected CreateCampaignBookingUseCase $createCampaignBooking,
        protected CheckBookingAvailabilityQuery $checkBookingAvailability,
        protected RescheduleBookingUseCase $rescheduleBooking,
        protected InventoryService $inventoryService,
        protected RevenueSplitResolverService $splitResolver,
    ) {
        $this->mediaService = $mediaService;
        $this->capacityService = $capacityService;
        $this->campaignPricingService = $campaignPricingService;
        $this->bookingConfig = $bookingConfig;
    }

    /**
     * Create a campaign booking with multiple screens and media.
     * POST /bookings/campaign
     */
    public function storeCampaign(Request $request)
    {
        $validated = $request->validate([
            'campaign_title' => 'required|string|max:255',
            'ad_frequency' => 'required|string|in:every_2_min,every_5_min,every_10_min,custom',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'daily_start_time' => 'nullable|date_format:H:i',
            'daily_end_time' => 'nullable|date_format:H:i',
            'screen_ids' => 'required|array|min:1',
            'screen_ids.*' => 'exists:screens,id',
            'quantities' => 'nullable|array',
            'quantities.*' => 'integer|min:1|max:500',
            'total_screens' => 'required|integer|min:1',
            'subtotal' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'referral_code_id' => 'nullable|exists:referral_codes,id',
            'referral_discount' => 'nullable|numeric|min:0',
            'final_amount' => 'required|numeric|min:0',
            'redeem_points' => 'nullable|integer|min:0',

            // Currency tracking fields
            'currency_code' => 'nullable|string|size:3',
            'exchange_rate_used' => 'nullable|numeric|min:0',
            'display_amount' => 'nullable|numeric|min:0',
            'country_code' => 'nullable|string|size:2',
            'pricing_plan_id' => 'nullable|exists:pricing_plans,id',
            'notes' => 'nullable|string',
            'advertisement_category_id' => [
                'required',
                Rule::exists('advertisement_categories', 'id')->where(function ($query) {
                    $query->where('status', 'active');
                }),
            ],
        ]);

        try {
            $booking = $this->createCampaignBooking->execute($validated, $request->user());
        } catch (BookingCreationException $e) {
            return $this->errorResponse($e->getMessage(), $e->status, $e->errors);
        }

        return $this->successResponse($booking, 'Campaign booking created. Proceed to payment.', 201);
    }

    /**
     * Upload media for a booking.
     * POST /bookings/{booking}/media
     */
    public function uploadMedia(Request $request, Booking $booking)
    {
        if ($booking->user_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $rules = $this->mediaService->batchValidationRules();
        $validated = $request->validate($rules);

        $uploaded = [];

        foreach ($request->file('files') as $index => $file) {
            $mediaData = $this->mediaService->upload($file, 'bookings/'.$booking->id);

            $media = BookingMedia::create([
                'booking_id' => $booking->id,
                'file_path' => $mediaData['file_path'],
                'file_type' => $mediaData['file_type'],
                'file_size' => $mediaData['file_size'],
                'mime_type' => $mediaData['mime_type'],
                'original_name' => $mediaData['original_name'],
                'sort_order' => $index,
            ]);

            $uploaded[] = array_merge($media->toArray(), [
                'url' => $mediaData['url'],
            ]);
        }

        // Dispatch notification event
        event(new MediaUploaded($booking, $uploaded));

        return $this->successResponse($uploaded, 'Media uploaded successfully', 201);
    }

    /**
     * Delete media from a booking.
     * DELETE /bookings/{booking}/media/{media}
     */
    public function deleteMedia(Request $request, Booking $booking, BookingMedia $media)
    {
        if ($booking->id !== $media->booking_id) {
            return $this->errorResponse('Media does not belong to this booking.', 422);
        }

        $this->mediaService->delete($media->file_path);
        $media->delete();

        return $this->successResponse(null, 'Media deleted successfully');
    }

    /**
     * Preview / estimate pricing for a campaign (no booking created).
     * POST /bookings/preview
     */
    public function preview(Request $request)
    {
        $validated = $request->validate([
            'screen_ids' => 'required|array|min:1',
            'screen_ids.*' => 'exists:screens,id',
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'duration_hours' => 'required|integer|min:1|max:720',
            'day' => 'required|string|in:Sun,Mon,Tue,Wed,Thu,Fri,Sat',
            'time' => 'required|date_format:H:i',
        ]);

        $calculator = app(\App\Services\BookingPriceCalculator::class);
        $pricingPlan = \App\Models\PricingPlan::with([
            'durationRules',
            'screenRules',
            'peakRules',
            'weekendRules',
        ])->findOrFail($validated['pricing_plan_id']);

        $screens = Screen::whereIn('id', $validated['screen_ids'])->get();
        $perScreenBreakdowns = [];
        $totalBasePrice = 0;
        $totalDiscount = 0;
        $totalScreenDiscount = 0;
        $totalDurationDiscount = 0;
        $totalPeak = 0;
        $totalWeekend = 0;

        foreach ($screens as $screen) {
            $breakdown = $calculator->calculate($pricingPlan, [
                'duration_hours' => $validated['duration_hours'],
                'day' => $validated['day'],
                'time' => $validated['time'],
                'screens' => count($screens),
            ]);

            $perScreenBreakdowns[] = [
                'screen_id' => $screen->id,
                'screen_name' => $screen->name,
                'breakdown' => $breakdown,
            ];

            $totalBasePrice += $breakdown['base_price'];
            $totalDiscount += $breakdown['discount_amount'];
            $totalScreenDiscount += $breakdown['screen_discount_amount'] ?? 0;
            $totalDurationDiscount += $breakdown['duration_discount_amount'] ?? 0;
            $totalPeak += $breakdown['peak_amount'];
            $totalWeekend += $breakdown['weekend_amount'];
        }

        $finalTotal = $totalBasePrice - $totalDiscount + $totalPeak + $totalWeekend;

        return $this->successResponse([
            'screens' => count($screens),
            'per_screen' => $perScreenBreakdowns,
            'aggregated' => [
                'base_price' => round($totalBasePrice, 2),
                'screen_count' => count($screens),
                'screen_discount' => round($totalScreenDiscount, 2),
                'duration_discount' => round($totalDurationDiscount, 2),
                'combined_discount' => round($totalDiscount, 2),
                'peak_adjustment' => round($totalPeak, 2),
                'weekend_adjustment' => round($totalWeekend, 2),
                'first_booking_discount' => 0.00,
                'referral_discount' => 0.00,
                'subtotal' => round($finalTotal, 2),
                'final_amount' => round($finalTotal, 2),
                // Backward compatibility keys
                'discount_amount' => round($totalDiscount, 2),
                'peak_amount' => round($totalPeak, 2),
                'weekend_amount' => round($totalWeekend, 2),
                'final_price' => round($finalTotal, 2),
            ],
            'applied_rules' => $perScreenBreakdowns[0]['breakdown']['applied_rules'] ?? [],
        ], 'Campaign pricing preview');
    }

    /**
     * Calculate campaign pricing using the new CampaignPricingService.
     * Uses each screen's attached pricing plan (NOT a single global plan).
     * POST /campaigns/calculate
     */
    public function calculateCampaignPricing(
        Request $request,
        \App\Application\Pricing\UseCases\CalculateCampaignPriceUseCase $calculateCampaignPriceUseCase
    ) {
        $validated = $request->validate([
            'screen_ids' => 'required|array|min:1',
            'screen_ids.*' => 'exists:screens,id',
            'quantities' => 'nullable|array',
            'quantities.*' => 'integer|min:1|max:500',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'daily_start_time' => 'required|date_format:H:i',
            'daily_end_time' => 'required|date_format:H:i',
            'referral_code_id' => 'nullable|exists:referral_codes,id',
            'country_code' => 'nullable|string|size:2',
        ]);

        $validated['user'] = Auth::guard('sanctum')->user();

        try {
            $result = $calculateCampaignPriceUseCase->execute($validated);

            return $this->successResponse(
                $result,
                'Campaign pricing calculated successfully'
            );
        } catch (\App\Domain\Pricing\Exceptions\PricingException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Validate campaign pricing data before booking creation.
     * POST /campaigns/validate
     */
    public function validateCampaignPricing(Request $request)
    {
        $validated = $request->validate([
            'screen_ids' => 'required|array|min:1',
            'screen_ids.*' => 'exists:screens,id',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'daily_start_time' => 'required|date_format:H:i',
            'daily_end_time' => 'required|date_format:H:i',
            'referral_code_id' => 'nullable|exists:referral_codes,id',
            'country_code' => 'nullable|string|size:2',
        ]);

        $validated['user'] = $request->user();
        $result = $this->campaignPricingService->validateCampaignPricing($validated);

        return $this->successResponse(
            $result,
            $result['valid'] ? 'Campaign pricing validated successfully' : 'Campaign pricing validation failed'
        );
    }

    /**
     * Approve a booking (admin only).
     * POST /bookings/{booking}/approve
     */
    public function approve(Request $request, Booking $booking)
    {
        if (! in_array($booking->status, [Booking::STATUS_MEDIA_REVIEW_PENDING, Booking::STATUS_PENDING_APPROVAL])) {
            return $this->errorResponse('Booking is not in a reviewable status.', 422);
        }

        // Verify all media has been reviewed before final approval
        $pendingMedia = $booking->bookingMedia()->where('review_status', BookingMedia::REVIEW_PENDING)->count();
        if ($pendingMedia > 0) {
            return $this->errorResponse(
                'All uploaded media must be reviewed before approval. '.$pendingMedia.' media item(s) pending review.',
                422
            );
        }

        $rejectedMedia = $booking->bookingMedia()->where('review_status', BookingMedia::REVIEW_REJECTED)->count();
        if ($rejectedMedia > 0) {
            return $this->errorResponse(
                $rejectedMedia.' media item(s) are rejected. Please resolve before approving.',
                422
            );
        }

        $booking->update([
            'status' => Booking::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'booking_confirmed',
            'label' => 'Campaign Approved',
            'description' => 'Campaign approved by '.($request->user()->name ?? 'admin').' — all media reviewed and validated.',
            'performed_by' => $request->user()->id,
        ]);

        // Dispatch notification event
        event(new BookingApproved($booking));

        return $this->successResponse($booking->fresh(['approver']), 'Campaign approved successfully');
    }

    /**
     * Reject a booking (admin only).
     * POST /bookings/{booking}/reject
     */
    public function reject(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        if (! in_array($booking->status, [Booking::STATUS_MEDIA_REVIEW_PENDING, Booking::STATUS_PENDING_APPROVAL])) {
            return $this->errorResponse('Booking is not in a reviewable status.', 422);
        }

        $booking->update([
            'status' => Booking::STATUS_REJECTED,
            'rejection_reason' => $validated['rejection_reason'],
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'status_changed',
            'label' => 'Campaign Rejected',
            'description' => $validated['rejection_reason'],
            'performed_by' => $request->user()->id,
        ]);

        // Dispatch notification event
        event(new BookingRejected($booking, $validated['rejection_reason']));

        return $this->successResponse($booking->fresh(), 'Campaign rejected');
    }

    /**
     * Get campaign booking details with all relations.
     * GET /bookings/{booking}/campaign
     */
    public function campaignDetails(Booking $booking)
    {
        $booking->load([
            'user',
            'bookingScreens.screen.city',
            'bookingScreens.city',
            'bookingMedia',
            'payments',
            'pricingPlan',
            'referralCode',
            'approver',
        ]);

        return $this->successResponse($booking, 'Campaign details fetched successfully');
    }

    /**
     * Get all bookings for the authenticated user with campaign details.
     * GET /my-bookings
     */
    public function myBookings(Request $request)
    {
        $perPage = $request->input('perPage', 10);
        $status = $request->input('status');

        $bookings = Booking::with([
            'bookingScreens.screen',
            'bookingMedia',
            'payments' => function ($q) {
                $q->latest();
            },
        ])
            ->where('user_id', $request->user()->id)
            ->when($status, function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->paginatedResponse($bookings, 'Bookings fetched successfully');
    }

    /**
     * Check daily ad capacity for a single screen.
     * POST /bookings/check-conflicts
     */
    public function checkConflicts(Request $request)
    {
        $validated = $request->validate([
            'screen_id' => 'required|exists:screens,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
        ]);

        $result = $this->checkBookingAvailability->execute(
            (int) $validated['screen_id'],
            $validated['start_time'],
            $validated['end_time'],
        );

        if ($result === null) {
            return $this->errorResponse('Screen not found', 404);
        }

        return $this->successResponse($result, 'Capacity check completed');
    }

    /**
     * Calendar view — includes both single-screen and campaign bookings.
     * For campaign bookings (via booking_screens pivot), we expand them into
     * individual calendar entries per screen so the timeline can display them.
     */
    public function calendar(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'screen_ids' => 'nullable|array',
            'screen_ids.*' => 'exists:screens,id',
            'status' => 'nullable|string',
            'country_id' => 'nullable|exists:countries,id',
            'city_id' => 'nullable|exists:cities,id',
            'pricing_plan_id' => 'nullable|exists:pricing_plans,id',
        ]);

        // ── 1) Single-screen bookings ──
        $singleScreenQuery = Booking::with(['screen:id,name,screen_code', 'pricingPlan:id,name'])
            ->whereNotNull('screen_id')
            ->where('start_time', '<', $validated['date_to'])
            ->where('end_time', '>', $validated['date_from']);

        if (! empty($validated['screen_ids'])) {
            $singleScreenQuery->whereIn('screen_id', $validated['screen_ids']);
        }

        if (! empty($validated['status'])) {
            $statuses = explode(',', $validated['status']);
            $singleScreenQuery->whereIn('status', $statuses);
        }

        if (! empty($validated['city_id'])) {
            $singleScreenQuery->whereHas('screen', fn ($q) => $q->where('city_id', $validated['city_id']));
        }

        if (! empty($validated['country_id'])) {
            $singleScreenQuery->whereHas('screen', fn ($q) => $q->where('country_id', $validated['country_id']));
        }

        if (! empty($validated['pricing_plan_id'])) {
            $singleScreenQuery->where('pricing_plan_id', $validated['pricing_plan_id']);
        }

        $singleScreenBookings = $singleScreenQuery->get([
            'id',
            'screen_id',
            'title',
            'campaign_title',
            'advertiser_name',
            'start_time',
            'end_time',
            'status',
            'price',
            'final_amount',
            'pricing_plan_id',
            'source',
        ]);

        // ── 2) Campaign bookings via booking_screens pivot ──
        $campaignQuery = Booking::with([
            'bookingScreens.screen:id,name,screen_code',
            'pricingPlan:id,name',
        ])
            ->where(function ($q) {
                $q->whereNull('screen_id')
                    ->orWhereHas('bookingScreens');
            })
            ->where('start_time', '<', $validated['date_to'])
            ->where('end_time', '>', $validated['date_from']);

        if (! empty($validated['status'])) {
            $statuses = explode(',', $validated['status']);
            $campaignQuery->whereIn('status', $statuses);
        }

        if (! empty($validated['pricing_plan_id'])) {
            $campaignQuery->where('pricing_plan_id', $validated['pricing_plan_id']);
        }

        if (! empty($validated['screen_ids'])) {
            $campaignQuery->whereHas('bookingScreens', function ($q) use ($validated) {
                $q->whereIn('screen_id', $validated['screen_ids']);
            });
        }

        if (! empty($validated['city_id'])) {
            $campaignQuery->whereHas('bookingScreens', function ($q) use ($validated) {
                $q->where('city_id', $validated['city_id']);
            });
        }

        $campaignBookings = $campaignQuery->get([
            'id',
            'title',
            'campaign_title',
            'advertiser_name',
            'start_time',
            'end_time',
            'status',
            'price',
            'final_amount',
            'pricing_plan_id',
            'source',
        ]);

        // Expand campaign bookings into individual calendar entries per screen
        $expandedCampaignEntries = collect();
        foreach ($campaignBookings as $booking) {
            $screens = $booking->bookingScreens;
            if ($screens->isEmpty()) {
                // Fallback: treat as single-screen entry
                $expandedCampaignEntries->push([
                    'id' => $booking->id,
                    'screen_id' => $booking->screen_id,
                    'title' => $booking->campaign_title ?: $booking->title,
                    'campaign_title' => $booking->campaign_title,
                    'advertiser_name' => $booking->advertiser_name,
                    'start_time' => $booking->start_time,
                    'end_time' => $booking->end_time,
                    'status' => $booking->status,
                    'price' => $booking->price,
                    'final_amount' => $booking->final_amount,
                    'pricing_plan_id' => $booking->pricing_plan_id,
                    'source' => $booking->source,
                    'is_campaign' => true,
                    'screen_name' => null,
                    'screen_code' => null,
                    'pricing_plan_name' => $booking->pricingPlan?->name,
                ]);
            } else {
                foreach ($screens as $bs) {
                    $expandedCampaignEntries->push([
                        'id' => $booking->id,
                        'screen_id' => $bs->screen_id,
                        'title' => $booking->campaign_title ?: $booking->title,
                        'campaign_title' => $booking->campaign_title,
                        'advertiser_name' => $booking->advertiser_name,
                        'start_time' => $booking->start_time,
                        'end_time' => $booking->end_time,
                        'status' => $booking->status,
                        'price' => $booking->price,
                        'final_amount' => $booking->final_amount,
                        'pricing_plan_id' => $booking->pricing_plan_id,
                        'source' => $booking->source,
                        'is_campaign' => true,
                        'screen_name' => $bs->screen?->name,
                        'screen_code' => $bs->screen?->screen_code,
                        'pricing_plan_name' => $booking->pricingPlan?->name,
                    ]);
                }
            }
        }

        // Transform single-screen bookings to same format
        $singleScreenEntries = $singleScreenBookings->map(function ($b) {
            return [
                'id' => $b->id,
                'screen_id' => $b->screen_id,
                'title' => $b->campaign_title ?: $b->title,
                'campaign_title' => $b->campaign_title,
                'advertiser_name' => $b->advertiser_name,
                'start_time' => $b->start_time,
                'end_time' => $b->end_time,
                'status' => $b->status,
                'price' => $b->price,
                'final_amount' => $b->final_amount,
                'pricing_plan_id' => $b->pricing_plan_id,
                'source' => $b->source,
                'is_campaign' => false,
                'screen_name' => $b->screen?->name,
                'screen_code' => $b->screen?->screen_code,
                'pricing_plan_name' => $b->pricingPlan?->name,
            ];
        });

        // Merge and return
        $allEntries = $singleScreenEntries->concat($expandedCampaignEntries);

        // Filter out any entries where screen_id is null (safety)
        $allEntries = $allEntries->whereNotNull('screen_id')->values();

        return $this->successResponse($allEntries, 'Calendar bookings fetched successfully');
    }

    /**
     * Occupancy stats — including per-screen breakdown for calendar sidebar.
     */
    public function occupancy(Request $request)
    {
        $maxAdsPerDay = $this->bookingConfig->maxAdsPerDay();

        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'screen_ids' => 'nullable|array',
            'screen_ids.*' => 'exists:screens,id',
        ]);

        $dateFrom = $validated['date_from'];
        $dateTo = $validated['date_to'];
        $screenIds = $validated['screen_ids'] ?? [];

        $activeStatuses = ['pending_approval', 'approved', 'scheduled', 'running', 'media_review_pending'];

        $singleScreenCounts = Booking::selectRaw('screen_id, COUNT(*) as count')
            ->whereNotNull('screen_id')
            ->where('start_time', '<', $dateTo)
            ->where('end_time', '>', $dateFrom)
            ->whereIn('status', $activeStatuses)
            ->when(! empty($screenIds), fn ($q) => $q->whereIn('screen_id', $screenIds))
            ->groupBy('screen_id')
            ->pluck('count', 'screen_id');

        $campaignCounts = DB::table('booking_screens')
            ->join('bookings', 'bookings.id', '=', 'booking_screens.booking_id')
            ->where('bookings.start_time', '<', $dateTo)
            ->where('bookings.end_time', '>', $dateFrom)
            ->whereIn('bookings.status', $activeStatuses)
            ->when(! empty($screenIds), fn ($q) => $q->whereIn('booking_screens.screen_id', $screenIds))
            ->selectRaw('booking_screens.screen_id, COUNT(*) as count')
            ->groupBy('booking_screens.screen_id')
            ->pluck('count', 'screen_id');

        $allScreenIds = $screenIds ?: Screen::where('status', 'active')->pluck('id')->toArray();
        $perScreen = [];
        foreach ($allScreenIds as $sid) {
            $total = (int) ($singleScreenCounts[$sid] ?? 0) + (int) ($campaignCounts[$sid] ?? 0);
            $occupancyPct = min(100, round(($total / $maxAdsPerDay) * 100, 1));
            $perScreen[(int) $sid] = [
                'active_bookings' => $total,
                'max_capacity' => $maxAdsPerDay,
                'occupancy_pct' => $occupancyPct,
            ];
        }

        $totalScreens = count($allScreenIds);
        $totalBookings = array_sum(array_column($perScreen, 'active_bookings'));
        $maxSlots = $totalScreens * $maxAdsPerDay;
        $occupancyRate = $maxSlots > 0 ? round(($totalBookings / $maxSlots) * 100, 2) : 0;

        return $this->successResponse([
            'total_screens' => $totalScreens,
            'total_bookings' => $totalBookings,
            'max_slots' => $maxSlots,
            'occupancy_rate' => $occupancyRate,
            'max_ads_per_day' => $maxAdsPerDay,
            'per_screen' => $perScreen,
        ], 'Occupancy fetched successfully');
    }

    /**
     * Dashboard KPIs.
     */
    public function kpis(Request $request)
    {
        $maxAdsPerDay = $this->bookingConfig->maxAdsPerDay();
        $today = Carbon::today();

        $bookingsToday = Booking::whereDate('created_at', $today)->count();

        $runningNow = Booking::runningNow()->count();

        $todayRevenue = Booking::whereDate('created_at', $today)
            ->where('status', '!=', Booking::STATUS_CANCELLED)
            ->sum('final_amount');

        $pendingApproval = Booking::where('status', Booking::STATUS_PENDING_APPROVAL)->count();

        $totalScreens = Screen::where('status', 'active')->count();
        $activeBookingsToday = Booking::whereDate('start_time', '<=', $today)
            ->whereDate('end_time', '>=', $today)
            ->whereIn('status', [Booking::STATUS_RUNNING, Booking::STATUS_SCHEDULED, Booking::STATUS_APPROVED])
            ->count();

        $occupancyRate = $totalScreens > 0
            ? round(($activeBookingsToday / ($totalScreens * $maxAdsPerDay)) * 100, 2)
            : 0;

        // First booking discount metrics
        $firstBookingDiscounts = \App\Models\Booking::where('first_booking_discount_applied', true)->count();
        $firstBookingDiscountAmount = (float) \App\Models\Booking::where('first_booking_discount_applied', true)->sum('first_booking_discount_amount');
        $firstBookingRevenue = (float) \App\Models\Booking::where('first_booking_discount_applied', true)->sum('final_amount');

        return $this->successResponse([
            'bookings_today' => $bookingsToday,
            'first_booking_discounts_issued' => $firstBookingDiscounts,
            'first_booking_discount_amount' => $firstBookingDiscountAmount,
            'first_booking_revenue' => $firstBookingRevenue,
            'running_now' => $runningNow,
            'today_revenue' => $todayRevenue,
            'pending_approval' => $pendingApproval,
            'total_screens' => $totalScreens,
            'occupancy_rate' => $occupancyRate,
            'max_ads_per_day' => $maxAdsPerDay,
        ], 'KPIs fetched successfully');
    }

    /**
     * Enhanced listing for admin with rich data, filters, and search.
     * GET /bookings (admin)
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 25);
        $search = $request->input('search');
        $status = $request->input('status');
        $paymentStatus = $request->input('payment_status');
        $source = $request->input('source');
        $pricingPlanId = $request->input('pricing_plan_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $screenId = $request->input('screen_id');
        $categoryId = $request->input('advertisement_category_id');
        $sessionStatus = $request->input('session_status'); // upcoming, live, ended

        $query = Booking::with([
            'screen:id,name,screen_code',
            'user:id,name,email',
            'pricingPlan:id,name',
            'payments' => function ($q) {
                $q->latest()->limit(1);
            },
            'bookingScreens.screen:id,name',
        ])
            ->select('bookings.*');

        // Text search across multiple fields
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('bookings.title', 'like', "%{$search}%")
                    ->orWhere('bookings.campaign_title', 'like', "%{$search}%")
                    ->orWhere('bookings.advertiser_name', 'like', "%{$search}%")
                    ->orWhere('bookings.advertiser_phone', 'like', "%{$search}%")
                    ->orWhere('bookings.advertiser_email', 'like', "%{$search}%")
                    ->orWhere('bookings.id', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($status) {
            $statuses = explode(',', $status);
            $query->whereIn('bookings.status', $statuses);
        }

        // Payment status filter (via payment relation)
        if ($paymentStatus) {
            $query->whereHas('payments', function ($q) use ($paymentStatus) {
                $q->where('payment_status', $paymentStatus);
            });
        } else {
            // ── Default: Exclude bookings whose latest payment failed ──
            // Only show failed-payment bookings when the user explicitly applies the filter.
            $query->where(function ($q) {
                $q->whereDoesntHave('payments')
                    ->orWhere(DB::raw('(SELECT payment_status FROM payments WHERE payments.booking_id = bookings.id ORDER BY created_at DESC LIMIT 1)'), '!=', 'failed');
            });
        }

        // Source filter
        if ($source) {
            $query->where('bookings.source', $source);
        }

        // Pricing plan (pack type) filter
        if ($pricingPlanId) {
            $query->where('bookings.pricing_plan_id', $pricingPlanId);
        }

        // Date range filter
        if ($dateFrom) {
            $query->where('bookings.start_time', '>=', Carbon::parse($dateFrom)->startOfDay());
        }
        if ($dateTo) {
            $query->where('bookings.end_time', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        // Advertisement category filter
        if ($categoryId) {
            $query->where('bookings.advertisement_category_id', $categoryId);
        }

        // Screen filter
        if ($screenId) {
            $query->where(function ($q) use ($screenId) {
                $q->where('bookings.screen_id', $screenId)
                    ->orWhereHas('bookingScreens', function ($sq) use ($screenId) {
                        $sq->where('screen_id', $screenId);
                    });
            });
        }

        // Session status filter
        if ($sessionStatus) {
            $now = now();
            switch ($sessionStatus) {
                case 'upcoming':
                    $query->where('bookings.start_time', '>', $now)
                        ->whereIn('bookings.status', [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED]);
                    break;
                case 'live':
                    $query->where('bookings.start_time', '<=', $now)
                        ->where('bookings.end_time', '>=', $now)
                        ->where('bookings.status', Booking::STATUS_RUNNING);
                    break;
                case 'ended':
                    $query->where('bookings.end_time', '<', $now);
                    break;
            }
        }

        // Filter for active sessions (including media review)
        if ($request->boolean('active_only')) {
            $query->whereIn('bookings.status', [
                Booking::STATUS_MEDIA_REVIEW_PENDING,
                Booking::STATUS_PENDING_APPROVAL,
                Booking::STATUS_APPROVED,
                Booking::STATUS_SCHEDULED,
                Booking::STATUS_RUNNING,
            ]);
        }

        $query->orderBy('bookings.created_at', 'desc');

        $bookings = $query->paginate($perPage);

        // Enrich each booking with derived statuses
        $bookings->getCollection()->transform(function ($booking) {
            return $this->enrichBooking($booking);
        });

        return $this->paginatedResponse($bookings, 'Bookings fetched successfully');
    }

    /**
     * Enhanced single booking with full details.
     * GET /bookings/{booking}
     */
    public function show(Booking $booking)
    {
        $booking->load([
            'screen',
            'bookingScreens.screen.city',
            'bookingScreens.settlement',
            'bookingMedia',
            'payments',
            'pricingPlan',
            'referralCode.partner',
            'user',
            'approver',
            'rewardTransactions',
            'rewardRedemptions',
            'bookingActivities' => function ($q) {
                $q->with('performedBy:id,name')->latest('created_at');
            },
        ]);

        return $this->successResponse(
            $this->enrichBooking($booking),
            'Booking details fetched successfully'
        );
    }

    /**
     * Add a note to a booking (admin).
     * POST /bookings/{booking}/notes
     */
    public function addNote(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'note' => 'required|string|max:2000',
        ]);

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'admin_note',
            'label' => 'Admin Note Added',
            'description' => $validated['note'],
            'performed_by' => $request->user()->id,
        ]);

        return $this->successResponse(null, 'Note added successfully');
    }

    /**
     * Start a session for a booking (admin).
     * POST /bookings/{booking}/start-session
     */
    public function startSession(Request $request, Booking $booking)
    {
        if (! in_array($booking->status, [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED])) {
            return $this->errorResponse('Booking must be approved or scheduled to start a session.', 422);
        }

        $booking->update([
            'status' => Booking::STATUS_RUNNING,
            'session_started_at' => now(),
        ]);

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'session_started',
            'label' => 'Session Started',
            'description' => 'Session started by '.($request->user()->name ?? 'admin'),
            'performed_by' => $request->user()->id,
        ]);

        return $this->successResponse($booking->fresh(), 'Session started successfully');
    }

    /**
     * End a session for a booking (admin).
     * POST /bookings/{booking}/end-session
     */
    public function endSession(Request $request, Booking $booking)
    {
        if ($booking->status !== Booking::STATUS_RUNNING) {
            return $this->errorResponse('Booking is not currently running.', 422);
        }

        $booking->update([
            'status' => Booking::STATUS_COMPLETED,
            'session_ended_at' => now(),
        ]);

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'session_ended',
            'label' => 'Session Ended',
            'description' => 'Session ended by '.($request->user()->name ?? 'admin'),
            'performed_by' => $request->user()->id,
        ]);

        return $this->successResponse($booking->fresh(), 'Session ended successfully');
    }

    /**
     * Extend a session for a booking (admin).
     * POST /bookings/{booking}/extend-session
     */
    public function extendSession(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'extra_minutes' => 'required|integer|min:1|max:1440',
        ]);

        $newEndTime = Carbon::parse($booking->end_time)->addMinutes($validated['extra_minutes']);

        $booking->update([
            'end_time' => $newEndTime,
        ]);

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'session_extended',
            'label' => 'Session Extended',
            'description' => 'Extended by '.$validated['extra_minutes'].' minutes to '.$newEndTime->format('M d, h:i A'),
            'metadata' => ['extra_minutes' => $validated['extra_minutes'], 'new_end_time' => $newEndTime->toDateTimeString()],
            'performed_by' => $request->user()->id,
        ]);

        return $this->successResponse($booking->fresh(), 'Session extended successfully');
    }

    /**
     * Mark a manual payment for a booking (admin).
     * POST /bookings/{booking}/manual-payment
     */
    public function markManualPayment(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => $validated['amount'],
            'currency' => 'INR',
            'payment_status' => 'paid',
            'payment_method' => 'manual',
            'paid_at' => now(),
            'metadata' => [
                'recorded_by' => $request->user()->id,
                'recorded_by_name' => $request->user()->name,
                'notes' => $validated['notes'] ?? '',
            ],
        ]);

        // Update booking status if payment_pending
        if ($booking->status === Booking::STATUS_PAYMENT_PENDING) {
            $booking->update(['status' => Booking::STATUS_PENDING_APPROVAL]);
        }

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'payment_completed',
            'label' => 'Manual Payment Recorded',
            'description' => 'Manual payment of '.number_format($validated['amount'], 2).' recorded by '.($request->user()->name ?? 'admin'),
            'metadata' => ['payment_id' => $payment->id, 'amount' => $validated['amount']],
            'performed_by' => $request->user()->id,
        ]);

        // Dispatch notification event
        event(new PaymentReceived($booking, $payment));

        return $this->successResponse($payment->load('booking'), 'Manual payment recorded');
    }

    /**
     * Resend confirmation for a booking (admin).
     * POST /bookings/{booking}/resend-confirmation
     */
    public function resendConfirmation(Request $request, Booking $booking)
    {
        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'status_changed',
            'label' => 'Confirmation Resent',
            'description' => 'Booking confirmation resent by '.($request->user()->name ?? 'admin'),
            'performed_by' => $request->user()->id,
        ]);

        // Dispatch notification event
        event(new BookingCreated($booking));

        return $this->successResponse(null, 'Confirmation resent successfully');
    }

    /**
     * Get booking timeline/activities.
     * GET /bookings/{booking}/timeline
     */
    public function timeline(Booking $booking)
    {
        $activities = $booking->bookingActivities()
            ->with('performedBy:id,name')
            ->latest('created_at')
            ->get();

        return $this->successResponse($activities, 'Booking timeline fetched successfully');
    }

    /**
     * Existing: Store legacy single-screen booking.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'screen_id' => 'required|exists:screens,id',
            'pricing_plan_id' => 'nullable|exists:pricing_plans,id',
            'referral_code_id' => 'nullable|exists:referral_codes,id',
            'title' => 'required|string|max:255',
            'advertiser_name' => 'required|string|max:255',
            'advertiser_email' => 'nullable|email|max:255',
            'advertiser_phone' => 'nullable|string|max:20',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'price' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $this->inventoryService->lockScreensForBooking([$validated['screen_id']]);

            $eligibility = $this->capacityService->checkEligibility(
                [$validated['screen_id']],
                $validated['start_time'],
                $validated['end_time']
            );

            if (! $eligibility->isEligible()) {
                return $this->errorResponse(
                    $eligibility->getFirstErrorMessage(),
                    422,
                    $eligibility->toArray()
                );
            }

            $booking = Booking::create(array_merge($validated, [
                'user_id' => $request->user()?->id,
                'status' => Booking::STATUS_PAYMENT_PENDING,
                'source' => 'admin',
            ]));

            $screen = Screen::find($validated['screen_id']);
            BookingScreen::create([
                'booking_id' => $booking->id,
                'screen_id' => $validated['screen_id'],
                'city_id' => $screen?->city_id,
                'quantity' => 1,
                'status' => 'pending',
            ]);

            BookingActivity::create([
                'booking_id' => $booking->id,
                'type' => 'created',
                'label' => 'Booking Created',
                'description' => 'Manual booking created by admin',
                'performed_by' => $request->user()?->id,
            ]);

            event(new BookingCreated($booking));

            return $this->successResponse($booking->load('bookingScreens.screen'), 'Booking created successfully', 201);
        });
    }

    /**
     * Existing: Update booking with full eligibility and self-exclusion verification.
     */
    public function update(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'screen_id' => 'sometimes|exists:screens,id',
            'screen_ids' => 'sometimes|array|min:1',
            'screen_ids.*' => 'exists:screens,id',
            'quantities' => 'sometimes|array',
            'pricing_plan_id' => 'nullable|exists:pricing_plans,id',
            'referral_code_id' => 'nullable|exists:referral_codes,id',
            'title' => 'sometimes|string|max:255',
            'advertiser_name' => 'sometimes|string|max:255',
            'advertiser_email' => 'nullable|email|max:255',
            'advertiser_phone' => 'nullable|string|max:20',
            'start_time' => 'sometimes|date',
            'end_time' => 'sometimes|date|after:start_time',
            'price' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|string',
            'notes' => 'nullable|string',
        ]);

        try {
            $booking = $this->rescheduleBooking->execute($booking, $validated, $request->user());
        } catch (BookingCreationException $e) {
            return $this->errorResponse($e->getMessage(), $e->status, $e->errors);
        }

        return $this->successResponse($booking, 'Booking updated successfully');
    }

    /**
     * Existing: Delete booking.
     */
    public function destroy(Booking $booking)
    {
        $booking->delete();

        return $this->successResponse(null, 'Booking deleted successfully');
    }

    /**
     * Approve a single media item (admin).
     * POST /bookings/{booking}/media/{media}/approve
     */
    public function approveMedia(Request $request, Booking $booking, BookingMedia $media)
    {
        if ($booking->id !== $media->booking_id) {
            return $this->errorResponse('Media does not belong to this booking.', 422);
        }

        $media->update([
            'review_status' => BookingMedia::REVIEW_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'booking_confirmed',
            'label' => 'Media Approved',
            'description' => 'Media "'.$media->original_name.'" approved by '.($request->user()->name ?? 'admin'),
            'metadata' => ['media_id' => $media->id, 'media_name' => $media->original_name],
            'performed_by' => $request->user()->id,
        ]);

        // Dispatch notification event
        event(new MediaApproved($booking, $media));

        return $this->successResponse($media->fresh('reviewer'), 'Media approved successfully');
    }

    /**
     * Reject a single media item (admin).
     * POST /bookings/{booking}/media/{media}/reject
     */
    public function rejectMedia(Request $request, Booking $booking, BookingMedia $media)
    {
        if ($booking->id !== $media->booking_id) {
            return $this->errorResponse('Media does not belong to this booking.', 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $media->update([
            'review_status' => BookingMedia::REVIEW_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'status_changed',
            'label' => 'Media Rejected',
            'description' => 'Media "'.$media->original_name.'" rejected: '.$validated['rejection_reason'],
            'metadata' => [
                'media_id' => $media->id,
                'media_name' => $media->original_name,
                'rejection_reason' => $validated['rejection_reason'],
            ],
            'performed_by' => $request->user()->id,
        ]);

        // Dispatch notification event
        event(new MediaRejected($booking, $media, $validated['rejection_reason']));

        return $this->successResponse($media->fresh('reviewer'), 'Media rejected');
    }

    /**
     * Get comprehensive campaign review data.
     * GET /bookings/{booking}/review
     */
    public function review(Booking $booking)
    {
        $booking->load([
            'user',
            'bookingScreens.screen.city',
            'bookingScreens.city',
            'bookingMedia' => function ($q) {
                $q->with('reviewer:id,name');
            },
            'payments',
            'pricingPlan',
            'referralCode.partner',
            'approver',
            'rewardTransactions',
            'rewardRedemptions',
            'bookingActivities' => function ($q) {
                $q->with('performedBy:id,name')->latest('created_at');
            },
        ]);

        $review = $this->enrichBooking($booking);

        // Media review summary
        $mediaStats = [
            'total' => $booking->bookingMedia->count(),
            'pending' => $booking->bookingMedia->where('review_status', BookingMedia::REVIEW_PENDING)->count(),
            'approved' => $booking->bookingMedia->where('review_status', BookingMedia::REVIEW_APPROVED)->count(),
            'rejected' => $booking->bookingMedia->where('review_status', BookingMedia::REVIEW_REJECTED)->count(),
        ];
        $review->media_stats = $mediaStats;

        // Capacity validation summary
        $capacityService = $this->capacityService;
        $screenIds = $booking->bookingScreens->pluck('screen_id')->toArray();
        if (! empty($screenIds) && $booking->start_time && $booking->end_time) {
            $capacityIssues = $capacityService->validateScreenDailyCapacity(
                $screenIds,
                $booking->start_time->format('Y-m-d'),
                $booking->end_time->format('Y-m-d')
            );
            $review->capacity_issues = $capacityIssues;
        } else {
            $review->capacity_issues = [];
        }

        // All media reviewed?
        $review->all_media_reviewed = $mediaStats['pending'] === 0;
        $review->can_approve = $mediaStats['pending'] === 0 && $mediaStats['rejected'] === 0;

        return $this->successResponse($review, 'Campaign review data fetched successfully');
    }

    /**
     * Move a booking from payment_pending to media_review_pending (after payment success).
     * This is typically called after payment verification.
     * POST /bookings/{booking}/mark-media-review
     */
    public function markMediaReview(Request $request, Booking $booking)
    {
        if ($booking->status !== Booking::STATUS_PAYMENT_PENDING && $booking->status !== Booking::STATUS_PAYMENT_FAILED) {
            return $this->errorResponse('Booking must have payment completed to enter media review.', 422);
        }

        $booking->update([
            'status' => Booking::STATUS_MEDIA_REVIEW_PENDING,
        ]);

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'status_changed',
            'label' => 'Media Review Required',
            'description' => 'Booking moved to media review — awaiting admin review of uploaded creatives.',
            'performed_by' => $request->user()->id,
        ]);

        return $this->successResponse($booking->fresh(), 'Booking moved to media review');
    }

    // ─── Protected Helpers ─────────────────────────────────

    /**
     * Enrich a booking with derived statuses and computed fields.
     */
    protected function enrichBooking($booking)
    {
        $now = now();

        // Session state
        if ($booking->status === Booking::STATUS_RUNNING) {
            $booking->session_state = 'live';
        } elseif ($booking->status === Booking::STATUS_COMPLETED || $booking->status === Booking::STATUS_CANCELLED) {
            $booking->session_state = 'ended';
        } elseif (in_array($booking->status, [Booking::STATUS_APPROVED, Booking::STATUS_SCHEDULED, Booking::STATUS_PENDING_APPROVAL])) {
            $booking->session_state = 'upcoming';
        } else {
            $booking->session_state = null;
        }

        // Payment derived status
        $latestPayment = $booking->payments->first();
        $booking->payment_status = $latestPayment?->payment_status ?? 'unpaid';

        // Computed amounts
        $booking->paid_amount = $booking->payments->where('payment_status', 'paid')->sum('amount');
        $booking->pending_amount = max(0, ($booking->final_amount ?? $booking->price) - $booking->paid_amount);

        // Booking source label
        $sourceLabels = [
            'website' => 'Website',
            'admin' => 'Admin',
            'manual' => 'Manual',
        ];
        $booking->source_label = $sourceLabels[$booking->source] ?? 'Unknown';

        // Duration in minutes
        if ($booking->start_time && $booking->end_time) {
            $booking->duration_minutes = $booking->start_time->diffInMinutes($booking->end_time);
        } else {
            $booking->duration_minutes = 0;
        }

        // IlluPoints Loyalty Summary
        $booking->loyalty = $booking->getLoyaltySummary();

        return $booking;
    }
}
