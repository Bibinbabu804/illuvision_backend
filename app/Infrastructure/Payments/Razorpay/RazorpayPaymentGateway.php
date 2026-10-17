<?php

namespace App\Infrastructure\Payments\Razorpay;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Config;
use Razorpay\Api\Api;

/**
 * RazorpayPaymentGateway
 *
 * Implements PaymentGatewayInterface by encapsulating the Razorpay PHP SDK.
 * Handles SDK initialization, vendor API calls, signature verification, and exception translation.
 */
class RazorpayPaymentGateway implements PaymentGatewayInterface
{
    protected Api $api;

    public function __construct(?Api $api = null)
    {
        if ($api !== null) {
            $this->api = $api;
        } else {
            $key = (string) Config::get('services.razorpay.key');
            $secret = (string) Config::get('services.razorpay.secret');
            $this->api = new Api($key, $secret);
        }
    }

    /**
     * Create a Razorpay order.
     */
    public function createOrder(array $params): array
    {
        $order = $this->api->order->create($params);
        return $order->toArray();
    }

    /**
     * Verify payment signature using Razorpay utility.
     */
    public function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        try {
            $attributes = [
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature,
            ];

            $this->api->utility->verifyPaymentSignature($attributes);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Fetch payment details from Razorpay.
     */
    public function fetchPayment(string $paymentId): array
    {
        try {
            $payment = $this->api->payment->fetch($paymentId);
            return $payment->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Process a refund for a payment on Razorpay.
     */
    public function refund(string $paymentId, ?int $amountInPaise = null, array $notes = []): array
    {
        $payload = [];
        if ($amountInPaise !== null) {
            $payload['amount'] = $amountInPaise;
        }
        if (!empty($notes)) {
            $payload['notes'] = $notes;
        }

        $refund = $this->api->payment->fetch($paymentId)->refund($payload);
        return $refund->toArray();
    }
}
