<?php

namespace App\Domain\Payment\Contracts;

/**
 * PaymentGatewayInterface
 *
 * Defines the contract for external payment gateway operations.
 * Free of vendor-specific SDK classes, response wrappers, or persistence side effects.
 */
interface PaymentGatewayInterface
{
    /**
     * Create an external payment order.
     *
     * @param array{receipt: string, amount: int, currency: string, notes?: array} $params
     * @return array Standardized order representation containing at least 'id', 'amount', 'currency'
     */
    public function createOrder(array $params): array;

    /**
     * Verify payment cryptographic signature.
     *
     * @param string $orderId
     * @param string $paymentId
     * @param string $signature
     * @return bool
     */
    public function verifySignature(string $orderId, string $paymentId, string $signature): bool;

    /**
     * Fetch payment details from external gateway.
     *
     * @param string $paymentId
     * @return array
     */
    public function fetchPayment(string $paymentId): array;

    /**
     * Process refund on external gateway.
     *
     * @param string $paymentId
     * @param int|null $amountInPaise
     * @param array $notes
     * @return array
     */
    public function refund(string $paymentId, ?int $amountInPaise = null, array $notes = []): array;
}
