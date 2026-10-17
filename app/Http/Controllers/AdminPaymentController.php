<?php

namespace App\Http\Controllers;

use App\Application\Financial\UseCases\RecordReferralAttributionUseCase;
use App\Application\Payment\UseCases\RefundPaymentUseCase;
use App\Support\Events\Notifications\PaymentRefunded;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\Refund;
use App\Services\Payment\PaymentAnalyticsService;
use App\Services\PaymentService;
use App\Services\Campaign\ReferralTrackingService;
use App\Services\RefundService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * AdminPaymentController
 *
 * Phase 0.9/0.10: refund *execution* and referral attribution now go through
 * application use cases. Listing/analytics/timeline reads are unchanged
 * (Reporting/Analytics is a later phase and remains a direct-query adapter).
 */
class AdminPaymentController extends Controller
{
    public function __construct(
        protected PaymentAnalyticsService $paymentAnalyticsService,
        protected RefundService $refundService,
        protected RefundPaymentUseCase $refundPaymentUseCase,
        protected RecordReferralAttributionUseCase $recordReferralAttribution,
        protected PaymentService $paymentService,
        protected ReferralTrackingService $referralTrackingService,
    ) {}

    /**
     * GET /payments
     * List all payments with filters, sorting, and pagination.
     */
    public function index(Request $request)
    {
        $query = Payment::with([
            'booking' => function ($q) {
                $q->select('id', 'user_id', 'campaign_title', 'advertiser_name', 'status', 'final_amount', 'created_at');
            },
            'booking.user' => function ($q) {
                $q->select('id', 'name', 'email');
            },
        ]);

        // Filters
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('booking_status')) {
            $query->whereHas('booking', function ($q) use ($request) {
                $q->where('status', $request->booking_status);
            });
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('is_refund')) {
            if ($request->boolean('is_refund')) {
                $query->whereNotNull('refund_status');
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->date_from));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->date_to));
        }

        if ($request->filled('refund_status')) {
            $query->whereHas('refunds', function ($q) use ($request) {
                $q->where('status', $request->refund_status);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('razorpay_order_id', 'like', "%{$search}%")
                    ->orWhere('razorpay_payment_id', 'like', "%{$search}%")
                    ->orWhereHas('booking', function ($bq) use ($search) {
                        $bq->where('campaign_title', 'like', "%{$search}%")
                            ->orWhere('advertiser_name', 'like', "%{$search}%");
                    });
            });
        }

        // Sorting
        $sortField = $request->input('sort_field', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $allowedSortFields = ['created_at', 'amount', 'payment_status', 'paid_at'];

        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
        } else {
            $query->latest();
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $payments = $query->paginate($perPage);

        return $this->successResponse($payments, 'Payments fetched successfully');
    }

    /**
     * GET /payments/{id}
     * Get detailed payment information.
     */
    public function show($id)
    {
        $payment = Payment::with([
            'booking.user',
            'booking.bookingScreens.screen.city.country',
            'booking.referralCode.partner',
            'booking.bookingMedia',
            'booking.bookingActivities',
            'refunds.initiator',
            'logs.performer',
        ])->findOrFail($id);

        // Get Razorpay details if payment ID exists
        $razorpayDetails = null;
        if ($payment->razorpay_payment_id) {
            try {
                $razorpayDetails = $this->paymentService->fetchPayment($payment->razorpay_payment_id);
            } catch (\Exception $e) {
                $razorpayDetails = null;
            }
        }

        // Get booking payment status context
        $bookingPaymentStatus = $payment->booking?->status;

        return $this->successResponse([
            'payment' => $payment,
            'razorpay_details' => $razorpayDetails,
            'booking_status' => $bookingPaymentStatus,
        ], 'Payment details fetched successfully');
    }

    /**
     * GET /payments/analytics
     * Get payment analytics and KPIs.
     */
    public function analytics(Request $request)
    {
        $filters = $this->parseFilters($request);

        $data = $this->paymentAnalyticsService->getAnalytics($filters);

        return $this->successResponse($data, 'Payment analytics fetched successfully');
    }

    /**
     * GET /payments/refunds
     * Get all refunds with filtering.
     */
    public function refunds(Request $request)
    {
        $filters = [
            'status' => $request->input('status'),
            'payment_id' => $request->input('payment_id'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'per_page' => $request->input('per_page', 20),
            'page' => $request->input('page', 1),
        ];

        $data = $this->refundService->getRefunds($filters);

        return $this->successResponse($data, 'Refunds fetched successfully');
    }

    /**
     * GET /payments/{id}/timeline
     * Get payment timeline/logs.
     */
    public function timeline($id)
    {
        $payment = Payment::findOrFail($id);

        $logs = PaymentLog::with('performer')
            ->where('payment_id', $payment->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse($logs, 'Payment timeline fetched successfully');
    }

    /**
     * POST /payments/{id}/refund
     * Initiate a refund for a payment.
     */
    public function initiateRefund(Request $request, $id)
    {
        $payment = Payment::findOrFail($id);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:'.$payment->amount,
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $refund = $this->refundPaymentUseCase->execute(
                $payment,
                (float) $validated['amount'],
                $validated['reason'] ?? null,
                $request->user()?->id
            );

            // Dispatch notification event
            event(new PaymentRefunded($payment, $validated['reason']));

            return $this->successResponse([
                'refund' => $refund->load('payment.booking'),
            ], 'Refund initiated successfully', 201);
        } catch (\Exception $e) {
            \Log::error('Refund failed: '.$e->getMessage());

            return $this->errorResponse('Refund failed: '.$e->getMessage(), 500);
        }
    }

    /**
     * GET /admin/payments/direct-verification-queue
     * List all direct payments awaiting admin verification.
     */
    public function directPayments(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $payments = Payment::with([
            'booking.user',
            'booking.referralCode.partner',
        ])
            ->whereIn('payment_status', [Payment::PAYMENT_STATUS_AWAITING_VERIFICATION, Payment::STATUS_VERIFICATION_PENDING, Payment::STATUS_PENDING])
            ->whereIn('payment_method', ['direct_bank', 'direct_upi', 'direct_cash', 'cheque', 'other', 'bank_transfer', 'cash', 'upi', 'manual'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->successResponse($payments, 'Direct payment verification queue fetched successfully');
    }

    /**
     * POST /admin/payments/{id}/approve-direct
     * Approve a direct payment, transition booking to pending_approval, and trigger referral tracking.
     */
    public function approveDirectPayment(Request $request, $id)
    {
        $payment = Payment::with(['booking.referralCode'])->findOrFail($id);

        if ($payment->payment_status === Payment::PAYMENT_STATUS_COMPLETED || $payment->payment_status === Payment::STATUS_PAID) {
            return $this->errorResponse('Payment is already approved/completed.', 422);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($payment, $request) {
            $admin = $request->user();

            $payment->update([
                'payment_status' => Payment::PAYMENT_STATUS_COMPLETED,
                'verification_status' => Payment::VERIFICATION_VERIFIED,
                'verified_by_admin_id' => $admin?->id,
                'verified_at' => now(),
                'paid_at' => now(),
            ]);

            $booking = $payment->booking;
            if ($booking) {
                $booking->update([
                    'status' => \App\Models\Booking::STATUS_PENDING_APPROVAL,
                ]);

                // Create referral usage and partner commission if referral code is attached
                if ($booking->referralCode) {
                    $this->recordReferralAttribution->execute(
                        $booking,
                        $payment->id,
                        $booking->referralCode
                    );
                }

                \App\Models\BookingActivity::create([
                    'booking_id' => $booking->id,
                    'type' => 'payment_verified',
                    'label' => 'Direct Payment Approved',
                    'description' => sprintf(
                        'Direct payment of ₹%s verified and approved by admin %s',
                        number_format($payment->amount, 2),
                        $admin?->name ?? 'System'
                    ),
                    'performed_by' => $admin?->id,
                ]);
            }

            PaymentLog::create([
                'payment_id' => $payment->id,
                'event' => 'direct_payment_approved',
                'description' => 'Direct payment approved by admin '.($admin?->name ?? 'System'),
                'performed_by' => $admin?->id,
            ]);

            return $this->successResponse([
                'payment' => $payment->fresh(['booking']),
            ], 'Direct payment approved and campaign transition initiated.');
        });
    }

    /**
     * POST /admin/payments/{id}/reject-direct
     * Reject a direct payment with reason.
     */
    public function rejectDirectPayment(Request $request, $id)
    {
        $payment = Payment::with('booking')->findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($payment, $validated, $request) {
            $admin = $request->user();

            $payment->update([
                'payment_status' => Payment::PAYMENT_STATUS_FAILED,
                'verification_status' => Payment::VERIFICATION_FAILED,
                'error_message' => $validated['reason'],
                'verified_by_admin_id' => $admin?->id,
                'verified_at' => now(),
            ]);

            $booking = $payment->booking;
            if ($booking) {
                $booking->update([
                    'status' => \App\Models\Booking::STATUS_PAYMENT_FAILED,
                    'rejection_reason' => $validated['reason'],
                ]);

                // Cancel referral tracking if present
                $this->referralTrackingService->cancelReferralTracking($booking);

                \App\Models\BookingActivity::create([
                    'booking_id' => $booking->id,
                    'type' => 'payment_failed',
                    'label' => 'Direct Payment Rejected',
                    'description' => sprintf(
                        'Direct payment of ₹%s rejected by admin %s. Reason: %s',
                        number_format($payment->amount, 2),
                        $admin?->name ?? 'System',
                        $validated['reason']
                    ),
                    'performed_by' => $admin?->id,
                ]);
            }

            PaymentLog::create([
                'payment_id' => $payment->id,
                'event' => 'direct_payment_rejected',
                'description' => 'Direct payment rejected: '.$validated['reason'],
                'performed_by' => $admin?->id,
            ]);

            return $this->successResponse([
                'payment' => $payment->fresh(['booking']),
            ], 'Direct payment rejected successfully.');
        });
    }

    /**
     * Parse common filter parameters from request.
     */
    protected function parseFilters(Request $request): array
    {
        $filters = [];

        if ($dateFrom = $request->input('date_from')) {
            $filters['date_from'] = Carbon::parse($dateFrom)->format('Y-m-d');
        }

        if ($dateTo = $request->input('date_to')) {
            $filters['date_to'] = Carbon::parse($dateTo)->endOfDay()->format('Y-m-d H:i:s');
        }

        return $filters;
    }
}
