<?php

namespace App\Http\Controllers;

use App\Application\Payment\UseCases\CreateOfflinePaymentUseCase;
use App\Support\Events\Notifications\BookingApproved;
use App\Support\Events\Notifications\BookingCreated;
use App\Support\Events\Notifications\MediaUploaded;
use App\Support\Events\Notifications\PaymentReceived;
use App\Models\Booking;
use App\Services\ManualBookingService;
use App\Services\MediaService;
use App\Support\Configs\BookingConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminManualBookingController extends Controller
{
    protected MediaService $mediaService;

    public function __construct(
        protected ManualBookingService $manualBookingService,
        protected CreateOfflinePaymentUseCase $createOfflinePayment,
        MediaService $mediaService,
    ) {
        $this->mediaService = $mediaService;
    }

    /**
     * Search for existing clients.
     * GET /admin/manual-bookings/search-clients?q=...
     */
    public function searchClients(Request $request)
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
        ]);

        $clients = $this->manualBookingService->searchClients($validated['q']);

        return $this->successResponse($clients, 'Clients fetched successfully');
    }

    /**
     * Calculate pricing for a manual campaign booking.
     * POST /admin/manual-bookings/calculate
     */
    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'screen_ids' => 'required|array|min:1',
            'screen_ids.*' => 'exists:screens,id',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'daily_start_time' => 'required|date_format:H:i',
            'daily_end_time' => 'required|date_format:H:i',
            'referral_code_id' => 'nullable|exists:referral_codes,id',
        ]);

        try {
            // Note: user is omitted at calculate-preview stage because the client
            // is not yet selected. FBD will be correctly evaluated when the booking
            // is actually created in createCampaignBooking (which passes the client).
            $result = $this->manualBookingService->calculatePricing($validated);

            return $this->successResponse(
                $result,
                'Manual booking pricing calculated successfully'
            );
        } catch (\App\Domain\Pricing\Exceptions\PricingException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Create a manual campaign booking.
     * POST /admin/manual-bookings
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // Client info
            'client.name' => 'required|string|max:255',
            'client.email' => 'nullable|email|max:255',
            'client.phone' => 'nullable|string|max:20',

            // Booking source
            'booking_source' => 'required|string|in:whatsapp,email,call,offline_sales,partner,admin_manual',

            // Campaign details
            'campaign_title' => 'required|string|max:255',
            'ad_frequency' => 'required|string|in:every_2_min,every_5_min,every_10_min,custom',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after_or_equal:start_time',
            'daily_start_time' => 'nullable|date_format:H:i',
            'daily_end_time' => 'nullable|date_format:H:i',

            // Screens
            'screen_ids' => 'required|array|min:1',
            'screen_ids.*' => 'exists:screens,id',
            'quantities' => 'nullable|array',
            'quantities.*' => 'integer|min:1|max:500',
            'total_screens' => 'required|integer|min:1',

            // Pricing
            'subtotal' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'referral_code_id' => 'nullable|exists:referral_codes,id',
            'referral_discount' => 'nullable|numeric|min:0',
            'final_amount' => 'required|numeric|min:0',
            'pricing_plan_id' => 'nullable|exists:pricing_plans,id',
            'advertisement_category_id' => [
                'required',
                \Illuminate\Validation\Rule::exists('advertisement_categories', 'id')->where(function ($query) {
                    $query->where('status', 'active');
                }),
            ],

            // Notes
            'notes' => 'nullable|string',
            'sales_notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',

            // Payment
            'payment_mode' => 'nullable|string|in:razorpay,bank_transfer,cash,upi,manual,pending',
            'offline_payment_reference' => 'nullable|string|max:255',

            // Approval
            'bypass_approval' => 'nullable|boolean',
        ]);

        try {
            $booking = $this->manualBookingService->createCampaignBooking(
                $validated,
                $request->user()
            );

            // Dispatch notification event
            event(new BookingCreated($booking));

            return $this->successResponse($booking, 'Manual campaign booking created successfully', 201);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    /**
     * Get manual booking details.
     * GET /admin/manual-bookings/{id}
     */
    public function show(Booking $booking)
    {
        $booking->load([
            'user',
            'bookingScreens.screen.city',
            'bookingScreens.city',
            'bookingScreens.settlement',
            'bookingMedia',
            'payments',
            'pricingPlan',
            'referralCode.partner',
            'approver',
            'createdByAdmin',
            'rewardTransactions',
            'rewardRedemptions',
            'bookingActivities' => function ($q) {
                $q->with('performedBy:id,name')->latest('created_at');
            },
        ]);

        $booking->loyalty = $booking->getLoyaltySummary();

        return $this->successResponse($booking, 'Manual booking details fetched successfully');
    }

    /**
     * Record an offline payment for a manual booking.
     * POST /admin/manual-bookings/{booking}/payment
     */
    public function payment(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'payment_mode' => 'required|string|in:bank_transfer,cash,upi,manual',
            'amount' => 'required|numeric|min:0',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $payment = $this->createOfflinePayment->execute(
                $booking,
                $validated,
                $request->user()
            );

            return $this->successResponse($payment, 'Offline payment recorded successfully');
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Approve a manual booking.
     * POST /admin/manual-bookings/{booking}/approve
     */
    public function approve(Request $request, Booking $booking)
    {
        if (!in_array($booking->status, [
            Booking::STATUS_MEDIA_REVIEW_PENDING,
            Booking::STATUS_PENDING_APPROVAL,
        ])) {
            return $this->errorResponse('Booking is not in a reviewable status.', 422);
        }

        // Verify all media has been reviewed before final approval
        $pendingMedia = $booking->bookingMedia()
            ->where('review_status', \App\Models\BookingMedia::REVIEW_PENDING)
            ->count();

        if ($pendingMedia > 0) {
            return $this->errorResponse(
                'All uploaded media must be reviewed before approval. ' . $pendingMedia . ' media item(s) pending review.',
                422
            );
        }

        $rejectedMedia = $booking->bookingMedia()
            ->where('review_status', \App\Models\BookingMedia::REVIEW_REJECTED)
            ->count();

        if ($rejectedMedia > 0) {
            return $this->errorResponse(
                $rejectedMedia . ' media item(s) are rejected. Please resolve before approving.',
                422
            );
        }

        $booking->update([
            'status' => Booking::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        \App\Models\BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'booking_confirmed',
            'label' => 'Campaign Approved',
            'description' => 'Manual campaign approved by ' . ($request->user()->name ?? 'admin'),
            'performed_by' => $request->user()->id,
        ]);

        // Dispatch notification event
        event(new BookingApproved($booking));

        return $this->successResponse(
            $booking->fresh(['approver']),
            'Manual campaign approved successfully'
        );
    }

    /**
     * Upload media for a manual booking.
     * POST /admin/manual-bookings/{booking}/media
     */
    public function uploadMedia(Request $request, Booking $booking)
    {
        $rules = $this->mediaService->batchValidationRules();
        $validated = $request->validate($rules);

        $uploaded = $this->manualBookingService->uploadMedia(
            $booking,
            $request->file('files'),
            $request->user()
        );

        // Dispatch notification event
        event(new MediaUploaded($booking, $uploaded));

        return $this->successResponse($uploaded, 'Media uploaded successfully', 201);
    }
}
