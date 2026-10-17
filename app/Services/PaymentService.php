<?php

namespace App\Services;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use App\Domain\Payment\Support\Money;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\BookingPricingBreakdown;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Campaign\ReferralTrackingService;

/**
 * PaymentService
 *
 * LEGACY SERVICE — retained as the transaction owner of the payment workflows
 * (Architecture Rule 7: strangler pattern; wrap and delegate, never duplicate).
 *
 * Phase 0.9 structural changes (behaviour-preserving):
 *  - Payment row lookups/creation now go through
 *    `PaymentRepositoryInterface` instead of inline Eloquent statics.
 *  - Rupee -> paise conversions now call `Money::toPaise()`, which reproduces
 *    the previous `(int) round($x * 100)` arithmetic exactly.
 *  - Full-refund determination now calls `Money::isFullRefund()`, preserving the
 *    asymmetric null-amount semantics of this specific path.
 *
 * Nothing else changed. In particular the signature verification, the failure
 * path (which is rolled back by the surrounding transaction), the referral
 * attribution and the booking transition are byte-for-byte unchanged.
 */
class PaymentService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected ReferralTrackingService $referralTrackingService,
        protected CampaignPricingService $campaignPricingService,
        protected PaymentRepositoryInterface $paymentRepository,
    ) {
    }

    /**
     * Create a payment order for a booking.
     */
    public function createOrder(Booking $booking): Payment
    {
        $amountInPaise = Money::toPaise($booking->final_amount);

        $orderData = $this->gateway->createOrder([
            'receipt' => 'booking_' . $booking->id . '_' . Str::random(6),
            'amount' => $amountInPaise,
            'currency' => Money::GATEWAY_CURRENCY,
            'notes' => [
                'booking_id' => $booking->id,
                'campaign_title' => $booking->campaign_title ?? $booking->title,
                'has_referral' => $booking->referral_code_id ? 'true' : 'false',
            ],
        ]);

        return $this->paymentRepository->create([
            'booking_id' => $booking->id,
            'razorpay_order_id' => $orderData['id'],
            'amount' => $booking->final_amount,
            'currency' => Money::GATEWAY_CURRENCY,
            'payment_status' => 'pending',
            'metadata' => [
                'razorpay_response' => $orderData,
            ],
        ]);
    }

    /**
     * Verify payment signature.
     * Returns true if signature is valid.
     */
    public function verifySignature(
        string $razorpayOrderId,
        string $razorpayPaymentId,
        string $razorpaySignature
    ): bool {
        return $this->gateway->verifySignature(
            $razorpayOrderId,
            $razorpayPaymentId,
            $razorpaySignature
        );
    }

    /**
     * Verify payment, create referral tracking, and confirm booking.
     *
     * Business flow (all in one transaction):
     * 1. Verify Razorpay signature
     * 2. Update payment status to paid
     * 3. Create referral usage + partner commission (if referral applied)
     * 4. Move booking to Pending Approval
     */
    public function verifyAndConfirmPayment(
        string $razorpayOrderId,
        string $razorpayPaymentId,
        string $razorpaySignature
    ): Payment {
        return DB::transaction(function () use ($razorpayOrderId, $razorpayPaymentId, $razorpaySignature) {
            $payment = $this->paymentRepository->findOrFailByGatewayOrderId($razorpayOrderId);

            if ($payment->payment_status === 'paid') {
                throw new \Exception('Payment already verified for this order.');
            }

            $verified = $this->verifySignature(
                $razorpayOrderId,
                $razorpayPaymentId,
                $razorpaySignature
            );

            if (!$verified) {
                $payment->update([
                    'razorpay_payment_id' => $razorpayPaymentId,
                    'razorpay_signature' => $razorpaySignature,
                    'payment_status' => 'failed',
                ]);

                $payment->booking->update(['status' => Booking::STATUS_PAYMENT_FAILED]);

                throw new \Exception('Payment signature verification failed.');
            }

            // ── Signature valid — confirm payment ──────────────
            $payment->update([
                'razorpay_payment_id' => $razorpayPaymentId,
                'razorpay_signature' => $razorpaySignature,
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);

            $booking = $payment->booking;

            // ── Create referral usage + commission (if applicable) ──
            if ($booking->referral_code_id) {
                $referralCode = \App\Models\ReferralCode::where('id', $booking->referral_code_id)->first();
                $referralResult = $this->referralTrackingService->createReferralTracking(
                    $booking,
                    $payment->id,
                    $referralCode
                );

                // Log referral tracking
                if ($referralResult['usage'] || $referralResult['commission']) {
                    \App\Models\BookingActivity::create([
                        'booking_id' => $booking->id,
                        'type' => 'referral_tracking',
                        'label' => 'Referral Tracked',
                        'description' => sprintf(
                            'Referral usage #%s created with commission ₹%s',
                            $referralResult['usage']?->id ?? 'N/A',
                            number_format($referralResult['commission']?->amount ?? 0, 2)
                        ),
                        'performed_by' => $booking->user_id,
                    ]);
                }
            }

            // ── Move booking to Pending Approval ────────────────
            $booking->update([
                'status' => Booking::STATUS_PENDING_APPROVAL,
            ]);

            return $payment->fresh(['booking']);
        });
    }

    /**
     * Fetch payment details from external gateway.
     */
    public function fetchPayment(string $razorpayPaymentId): array
    {
        return $this->gateway->fetchPayment($razorpayPaymentId);
    }

    /**
     * Process a refund for a payment.
     * Also updates referral usage and commission status if applicable.
     *
     * NOTE: legacy/unrouted path. See RefundPaymentUseCase for the two distinct
     * refund business paths.
     */
    public function refund(Payment $payment, ?float $amount = null): Payment
    {
        return DB::transaction(function () use ($payment, $amount) {
            $refundAmount = $amount ? Money::toPaise($amount) : null;

            $razorpayRefund = $this->gateway->refund(
                $payment->razorpay_payment_id,
                $refundAmount,
                [
                    'booking_id' => $payment->booking_id,
                    'payment_id' => $payment->id,
                ]
            );

            $isFullRefund = Money::isFullRefund($amount, $payment->amount);

            $payment->update([
                'payment_status' => $isFullRefund ? 'refunded' : 'partially_refunded',
                'refund_status' => $razorpayRefund['status'] ?? 'processed',
                'metadata' => array_merge($payment->metadata ?? [], [
                    'refund' => is_array($razorpayRefund) ? $razorpayRefund : $razorpayRefund->toArray(),
                ]),
            ]);

            if ($isFullRefund) {
                $payment->booking->update(['status' => Booking::STATUS_REFUNDED]);

                // Update referral tracking status for full refunds
                $this->referralTrackingService->refundReferralTracking($payment->booking);
            }

            return $payment->fresh();
        });
    }
}
