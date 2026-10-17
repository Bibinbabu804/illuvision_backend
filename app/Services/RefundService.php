<?php

namespace App\Services;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Support\Money;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

/**
 * RefundService
 *
 * Owns the *recorded* refund path: it creates a `refunds` ledger row, writes a
 * `payment_logs` audit row, and transitions the payment and booking.
 *
 * PHASE 0.10 STRUCTURAL CHANGE (behaviour-preserving):
 * The Razorpay SDK is no longer used directly. The refund call now goes through
 * `PaymentGatewayInterface::refund()` (implemented by
 * `App\Infrastructure\Payments\Razorpay\RazorpayPaymentGateway`), which
 * performs the identical `$api->payment->fetch($id)->refund($payload)` request
 * and returns `$refund->toArray()`.
 *
 * Call-by-call equivalence:
 *   before: payment->fetch($id)->refund(['amount' => paise, 'notes' => [...]])
 *   after : gateway->refund($id, paise, [...])  -> fetch($id)->refund(same payload)
 *   $razorpayRefund['id']            -> identical (array access on the same data)
 *   $razorpayRefund->toArray()       -> identical (adapter returns toArray())
 *   gateway/SDK exception propagation -> identical (adapter does not catch here)
 *   `amount` is always supplied, so the adapter's null-amount omission branch is
 *   never taken and no payload key is added or removed.
 *
 * The external gateway call still happens INSIDE the DB transaction. That is a
 * pre-existing condition (Architecture Rule 4 prefers the opposite) and is
 * recorded, not changed, in
 * docs/audit/phase-0.9-0.10-financial-concurrency-verification.json.
 */
class RefundService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway
    ) {
    }

    /**
     * Initiate a refund for a payment.
     */
    public function initiateRefund(Payment $payment, float $amount, string $reason = null, ?int $initiatedBy = null): Refund
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $initiatedBy) {
            // Process via the payment gateway abstraction
            $razorpayRefund = $this->gateway->refund(
                $payment->razorpay_payment_id,
                Money::toPaise($amount),
                [
                    'payment_id' => $payment->id,
                    'booking_id' => $payment->booking_id,
                    'reason' => $reason ?? 'No reason provided',
                ]
            );

            $refund = Refund::create([
                'payment_id' => $payment->id,
                'amount' => $amount,
                'status' => Refund::STATUS_PROCESSED,
                'reason' => $reason,
                'razorpay_refund_id' => $razorpayRefund['id'],
                'refund_reference' => $razorpayRefund['id'],
                'initiated_by' => $initiatedBy,
                'initiated_at' => now(),
                'processed_at' => now(),
                'metadata' => ['razorpay_response' => $razorpayRefund],
            ]);

            // Update payment status
            $isFullRefund = Money::isFullRefund($amount, $payment->amount);
            $payment->update([
                'payment_status' => $isFullRefund ? Payment::STATUS_REFUNDED : Payment::STATUS_PARTIALLY_REFUNDED,
                'refund_status' => Payment::REFUND_PROCESSED,
                'razorpay_refund_id' => $razorpayRefund['id'],
            ]);

            // Update booking status for full refunds
            if ($isFullRefund) {
                $payment->booking->update(['status' => \App\Models\Booking::STATUS_REFUNDED]);
            }

            // Log the refund event
            PaymentLog::create([
                'payment_id' => $payment->id,
                'event' => PaymentLog::EVENT_REFUND_PROCESSED,
                'label' => 'Refund Processed',
                'description' => $isFullRefund
                    ? "Full refund of ₹" . number_format($amount, 2) . " processed" . ($reason ? ": {$reason}" : "")
                    : "Partial refund of ₹" . number_format($amount, 2) . " processed" . ($reason ? ": {$reason}" : ""),
                'metadata' => ['refund_id' => $refund->id, 'razorpay_refund_id' => $razorpayRefund['id']],
                'performed_by' => $initiatedBy,
            ]);

            return $refund->fresh(['payment']);
        });
    }

    /**
     * Mark a refund as failed.
     */
    public function markRefundFailed(Refund $refund, string $error = null): Refund
    {
        return DB::transaction(function () use ($refund, $error) {
            $refund->update([
                'status' => Refund::STATUS_FAILED,
                'metadata' => array_merge($refund->metadata ?? [], ['error' => $error]),
            ]);

            PaymentLog::create([
                'payment_id' => $refund->payment_id,
                'event' => PaymentLog::EVENT_REFUND_FAILED,
                'label' => 'Refund Failed',
                'description' => "Refund failed: {$error}",
                'metadata' => ['refund_id' => $refund->id],
            ]);

            return $refund->fresh();
        });
    }

    /**
     * Get refunds list with filters.
     */
    public function getRefunds(array $filters = []): array
    {
        $query = Refund::with(['payment.booking', 'initiator']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['payment_id'])) {
            $query->where('payment_id', $filters['payment_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $perPage = $filters['per_page'] ?? 20;
        $page = $filters['page'] ?? 1;

        $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginated->items(),
            'current_page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'last_page' => $paginated->lastPage(),
        ];
    }
}
