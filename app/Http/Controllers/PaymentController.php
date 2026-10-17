<?php

namespace App\Http\Controllers;

use App\Application\Payment\UseCases\CreatePaymentOrderUseCase;
use App\Application\Payment\UseCases\GetPaymentStatusUseCase;
use App\Application\Payment\UseCases\RefundPaymentUseCase;
use App\Application\Payment\UseCases\VerifyPaymentUseCase;
use App\Support\Events\Notifications\PaymentFailed;
use App\Support\Events\Notifications\PaymentReceived;
use App\Support\Events\Notifications\PaymentRefunded;
use App\Models\Booking;
use Illuminate\Http\Request;

/**
 * PaymentController
 *
 * Phase 0.9: the controller is now a pure HTTP adapter. All payment behaviour
 * is delegated to application use cases; it holds no persistence access, no
 * gateway access and no financial logic.
 *
 * Response envelopes, status codes, validation rules, messages and error strings
 * are unchanged.
 */
class PaymentController extends Controller
{
    public function __construct(
        protected CreatePaymentOrderUseCase $createPaymentOrderUseCase,
        protected VerifyPaymentUseCase $verifyPaymentUseCase,
        protected GetPaymentStatusUseCase $getPaymentStatusUseCase,
        protected RefundPaymentUseCase $refundPaymentUseCase,
    ) {
    }

    /**
     * Create a Razorpay order for a booking.
     * POST /payments/razorpay/create-order
     */
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
        ]);

        $booking = Booking::with('payments')->findOrFail($validated['booking_id']);

        // Verify the booking belongs to the authenticated user
        if ($booking->user_id !== $request->user()->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Check booking is in payment_pending status
        if ($booking->status !== Booking::STATUS_PAYMENT_PENDING) {
            return $this->errorResponse('Booking is not in payment pending status.', 422);
        }

        try {
            $result = $this->createPaymentOrderUseCase->execute($booking);

            $statusCode = $result['is_existing'] ? 200 : 201;
            $message = $result['is_existing'] ? 'Existing pending payment found' : 'Razorpay order created successfully';

            return $this->successResponse([
                'payment' => $result['payment'],
                'razorpay_order_id' => $result['razorpay_order_id'],
                'amount' => $result['amount'],
                'currency' => $result['currency'],
            ], $message, $statusCode);
        } catch (\Exception $e) {
            \Log::error('Failed to create payment order: ' . $e->getMessage());
            return $this->errorResponse('Failed to create payment order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Verify Razorpay payment signature and confirm booking.
     * POST /payments/razorpay/verify
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        try {
            $payment = $this->verifyPaymentUseCase->execute(
                $validated['razorpay_order_id'],
                $validated['razorpay_payment_id'],
                $validated['razorpay_signature']
            );

            // Dispatch notification event
            event(new PaymentReceived($payment->booking, $payment));

            return $this->successResponse([
                'payment' => $payment->load('booking'),
                'booking' => $payment->booking,
                'status' => 'verified',
            ], 'Payment verified successfully. Booking is pending approval.');
        } catch (\Exception $e) {
            // Dispatch payment failed notification if we can determine the booking
            if (isset($payment) && isset($payment->booking)) {
                event(new PaymentFailed($payment));
            }
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    /**
     * Get payment status for a booking.
     * GET /bookings/{id}/payment-status
     */
    public function paymentStatus($bookingId)
    {
        return $this->successResponse(
            $this->getPaymentStatusUseCase->execute((int) $bookingId),
            'Payment status fetched successfully'
        );
    }

    /**
     * Process a refund (admin only).
     * POST /payments/{payment}/refund
     *
     * NOTE: this route action is currently NOT registered in routes/api.php; the
     * live admin refund endpoint is AdminPaymentController::initiateRefund(),
     * which performs the *recorded* refund path. See RefundPaymentUseCase.
     */
    public function refund(Request $request, $paymentId)
    {
        $payment = \App\Models\Payment::findOrFail($paymentId);

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0|max:' . $payment->amount,
        ]);

        try {
            $refunded = $this->refundPaymentUseCase->executeLegacyGatewayRefund(
                $payment,
                $validated['amount'] ?? null
            );

            // Dispatch notification event
            if ($refunded->booking) {
                event(new PaymentRefunded($refunded));
            }

            return $this->successResponse([
                'payment' => $refunded,
            ], 'Refund processed successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Refund failed: ' . $e->getMessage(), 500);
        }
    }
}
