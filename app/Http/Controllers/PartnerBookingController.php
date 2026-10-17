<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Partner;
use App\Models\User;
use App\Services\ManualBookingService;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerBookingController extends Controller
{
    public function __construct(
        protected ManualBookingService $delegatedBookingEngine,
        protected MediaService $mediaService
    ) {}

    /**
     * Get list of campaigns created by the authenticated Partner.
     * GET /partner/campaigns
     */
    public function index(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (! $partner) {
            return $this->errorResponse('Partner account not found or not approved.', 403);
        }

        $perPage = (int) $request->input('per_page', 15);
        $status = $request->input('status');

        $query = Booking::where('created_by_partner_id', $partner->id)
            ->with(['user', 'bookingScreens.screen', 'payments'])
            ->orderBy('created_at', 'desc');

        if (! empty($status)) {
            $query->where('status', $status);
        }

        $campaigns = $query->paginate($perPage);

        return $this->successResponse($campaigns, 'Partner campaigns fetched successfully.');
    }

    /**
     * Get Partner's managed clients.
     * GET /partner/clients
     */
    public function clients(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (! $partner) {
            return $this->errorResponse('Partner account not found or not approved.', 403);
        }

        $clients = User::where('created_by_partner_id', $partner->id)
            ->withCount('bookings')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'status', 'created_at']);

        return $this->successResponse($clients, 'Partner clients fetched successfully.');
    }

    /**
     * Create a managed client under the authenticated Partner.
     * POST /partner/clients
     */
    public function storeClient(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (! $partner) {
            return $this->errorResponse('Partner account not found or not approved.', 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $client = $this->delegatedBookingEngine->findOrCreateClient($validated, $partner->id);

        return $this->successResponse($client, 'Client profile registered under Partner.', 201);
    }

    /**
     * Calculate pricing preview for Partner campaign booking.
     * POST /partner/bookings/calculate
     */
    public function calculate(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (! $partner) {
            return $this->errorResponse('Partner account not found or not approved.', 403);
        }

        $validated = $request->validate([
            'screen_ids' => 'required|array|min:1',
            'screen_ids.*' => 'nullable',
            'quantities' => 'nullable|array',
            'start_date' => 'nullable',
            'end_date' => 'nullable',
            'start_time' => 'nullable',
            'end_time' => 'nullable',
            'daily_start_time' => 'nullable|string',
            'daily_end_time' => 'nullable|string',
        ]);

        $rawStart = $validated['start_time'] ?? $validated['start_date'] ?? null;
        $rawEnd = $validated['end_time'] ?? $validated['end_date'] ?? null;

        $startDate = $rawStart ? date('Y-m-d', strtotime($rawStart)) : date('Y-m-d', strtotime('+1 day'));
        $endDate = $rawEnd ? date('Y-m-d', strtotime($rawEnd)) : date('Y-m-d', strtotime('+4 days'));

        $startTimeFormatted = $rawStart ? date('H:i', strtotime($rawStart)) : '06:00';
        $endTimeFormatted = $rawEnd ? date('H:i', strtotime($rawEnd)) : '23:00';

        $dailyStartTime = $validated['daily_start_time'] ?? ($startTimeFormatted !== '00:00' ? $startTimeFormatted : '06:00');
        $dailyEndTime = $validated['daily_end_time'] ?? ($endTimeFormatted !== '00:00' ? $endTimeFormatted : '23:00');

        $validated['start_date'] = $startDate;
        $validated['end_date'] = $endDate;
        $validated['daily_start_time'] = $dailyStartTime;
        $validated['daily_end_time'] = $dailyEndTime;

        // Auto-resolve active Partner referral code
        $activeReferralCode = $partner->referralCodes()->where('status', 'active')->first();
        if ($activeReferralCode) {
            $validated['referral_code_id'] = $activeReferralCode->id;
        }

        try {
            $result = $this->delegatedBookingEngine->calculatePricing($validated);

            return $this->successResponse($result, 'Partner campaign pricing calculated.');
        } catch (\App\Domain\Pricing\Exceptions\PricingException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Create a campaign booking for a client from Partner Portal.
     * POST /partner/bookings
     */
    public function store(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (! $partner) {
            return $this->errorResponse('Partner account not found or not approved.', 403);
        }

        $validated = $request->validate([
            // Client details
            'client.name' => 'required|string|max:255',
            'client.email' => 'nullable|email|max:255',
            'client.phone' => 'nullable|string|max:20',

            // Campaign details
            'campaign_title' => 'required|string|max:255',
            'ad_frequency' => 'nullable|string',
            'start_time' => 'required|date',
            'end_time' => 'required|date',
            'daily_start_time' => 'nullable|string',
            'daily_end_time' => 'nullable|string',

            // Screens
            'screen_ids' => 'required|array|min:1',
            'screen_ids.*' => 'nullable',
            'quantities' => 'nullable|array',
            'quantities.*' => 'integer|min:1|max:500',
            'total_screens' => 'nullable|integer',

            // Pricing
            'subtotal' => 'nullable|numeric',
            'discount_amount' => 'nullable|numeric',
            'referral_discount' => 'nullable|numeric',
            'final_amount' => 'nullable|numeric',

            // Payment Mode choice (made optional/flexible reference)
            'payment_mode' => 'nullable|string',
            'offline_payment_reference' => 'nullable|string|max:255',
            'payment_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'advertisement_category_id' => 'nullable|integer',
        ]);

        if (isset($validated['screen_ids']) && is_array($validated['screen_ids'])) {
            $validated['screen_ids'] = array_values(array_unique(array_filter($validated['screen_ids'])));
        }

        // Auto-inject Partner referral code
        $activeReferralCode = $partner->referralCodes()->where('status', 'active')->first();
        if ($activeReferralCode) {
            $validated['referral_code_id'] = $activeReferralCode->id;
        }

        // Attach Partner Context metadata
        $validated['created_by_partner_id'] = $partner->id;
        $validated['source'] = Booking::SOURCE_PARTNER;
        $validated['booking_source'] = 'partner_portal';

        // Direct payment modes enter pending admin verification
        if (($validated['payment_mode'] ?? null) !== 'online_razorpay') {
            $validated['bypass_approval'] = false;
        }

        try {
            $booking = $this->delegatedBookingEngine->createCampaignBooking($validated, $request->user());

            return $this->successResponse([
                'booking' => $booking->load(['user', 'screens', 'referralCode']),
            ], 'Partner client campaign booking created successfully.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Get campaign details and timeline for a partner's booking.
     * GET /partner/bookings/{booking}
     */
    public function show(Request $request, Booking $booking): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (! $partner || $booking->created_by_partner_id !== $partner->id) {
            return $this->errorResponse('Booking not found or access denied.', 404);
        }

        $booking->load([
            'user',
            'bookingScreens.screen.city',
            'bookingMedia',
            'payments',
            'referralCode',
            'bookingActivities' => fn ($q) => $q->with('performedBy:id,name')->latest('created_at'),
        ]);

        return $this->successResponse($booking, 'Partner campaign details fetched successfully.');
    }

    /**
     * Upload creative media for a partner's booking.
     * POST /partner/bookings/{booking}/media
     */
    public function uploadMedia(Request $request, Booking $booking): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (! $partner || $booking->created_by_partner_id !== $partner->id) {
            return $this->errorResponse('Booking not found or access denied.', 404);
        }

        $validated = $request->validate([
            'file' => 'required|file|mimes:jpeg,png,mp4,mov,avi|max:102400', // max 100MB
            'duration' => 'nullable|integer|min:1',
        ]);

        try {
            $media = $this->mediaService->uploadBookingMedia($booking, $validated['file'], [
                'duration' => $validated['duration'] ?? null,
                'review_status' => \App\Models\BookingMedia::REVIEW_PENDING,
            ]);

            \App\Models\BookingActivity::create([
                'booking_id' => $booking->id,
                'type' => 'media_uploaded',
                'label' => 'Creative Uploaded by Partner',
                'description' => sprintf('Media file "%s" uploaded by Partner for client %s', $media->original_name, $booking->advertiser_name),
                'performed_by' => $request->user()->id,
            ]);

            // Move booking to media review pending if payment is completed/verified
            if (in_array($booking->status, [Booking::STATUS_PENDING_APPROVAL, Booking::STATUS_PAYMENT_PENDING])) {
                $booking->update(['status' => Booking::STATUS_MEDIA_REVIEW_PENDING]);
            }

            return $this->successResponse($media, 'Creative uploaded successfully for campaign review.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse('Creative upload failed: '.$e->getMessage(), 422);
        }
    }

    /**
     * Replace or remove rejected creative media item.
     * DELETE /partner/bookings/{booking}/media/{media}
     */
    public function deleteMedia(Request $request, Booking $booking, \App\Models\BookingMedia $media): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (! $partner || $booking->created_by_partner_id !== $partner->id || $media->booking_id !== $booking->id) {
            return $this->errorResponse('Media or booking not found or access denied.', 404);
        }

        $this->mediaService->deleteBookingMedia($media);

        \App\Models\BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'media_deleted',
            'label' => 'Creative Removed',
            'description' => sprintf('Creative "%s" removed by Partner', $media->original_name),
            'performed_by' => $request->user()->id,
        ]);

        return $this->successResponse(null, 'Creative media item removed successfully.');
    }

    /**
     * Get activity timeline for a partner's booking.
     * GET /partner/bookings/{booking}/timeline
     */
    public function timeline(Request $request, Booking $booking): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (! $partner || $booking->created_by_partner_id !== $partner->id) {
            return $this->errorResponse('Booking not found or access denied.', 404);
        }

        $activities = $booking->bookingActivities()
            ->with('performedBy:id,name')
            ->latest('created_at')
            ->get();

        return $this->successResponse($activities, 'Campaign timeline fetched successfully.');
    }

    /**
     * Resolve authenticated Partner model.
     */
    private function getAuthenticatedPartner(Request $request): ?Partner
    {
        $user = $request->user();
        if (! $user || $user->role !== 'partner') {
            return null;
        }

        return Partner::where('user_id', $user->id)->where('status', Partner::STATUS_APPROVED)->first();
    }
}
