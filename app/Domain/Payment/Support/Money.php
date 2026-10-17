<?php

namespace App\Domain\Payment\Support;

/**
 * Money
 *
 * Pure, deterministic minor-unit (paise) conversion for the Payment boundary.
 *
 * ARCHITECTURAL NOTE (Phase 0.9):
 * This class is an *extraction*, not a redesign. Every method reproduces the
 * exact arithmetic that already existed inline in the legacy services, so that
 * the single conversion boundary is auditable in one place:
 *
 *   rupees  -> paise : (int) round($rupees * 100)
 *   paise   -> rupees: round($paise / 100, 2)
 *
 * No float rounding mode, no BCMath, and no currency conversion is introduced
 * here. `float` is NOT used for persistence — persistence continues to use the
 * `decimal:2` Eloquent cast on the `payments.amount` column.
 */
final class Money
{
    /** Minor units per major unit for the supported settlement currency (INR). */
    public const MINOR_UNITS_PER_MAJOR = 100;

    /** The only currency the online payment gateway currently settles in. */
    public const GATEWAY_CURRENCY = 'INR';

    /**
     * Convert a rupee (major-unit) amount into integer paise (minor units).
     *
     * Reproduces the legacy expression `(int) round($amount * 100)` exactly,
     * including PHP's default half-up rounding of `round()`.
     *
     * @param  float|int|string  $rupees
     * @return int Positive integer amount in paise.
     */
    public static function toPaise($rupees): int
    {
        return (int) round(((float) $rupees) * self::MINOR_UNITS_PER_MAJOR);
    }

    /**
     * Convert integer paise (minor units) back into a rupee (major-unit) amount.
     *
     * Reproduces the legacy expression `round($paise / 100, 2)` exactly.
     */
    public static function toRupees(int $paise): float
    {
        return round($paise / self::MINOR_UNITS_PER_MAJOR, 2);
    }

    /**
     * Normalise an arbitrary rupee amount to the 2-decimal persistence scale.
     *
     * Reproduces the legacy expression `round($amount, 2)`.
     */
    public static function normalise($rupees): float
    {
        return round((float) $rupees, 2);
    }

    /**
     * Whether a gateway refund/refund request is for the entire payment amount.
     *
     * Reproduces the legacy expressions used at both refund call sites:
     *   PaymentService::refund()        -> is_null($amount) || $amount >= $payment->amount
     *   RefundService::initiateRefund() -> $amount >= $payment->amount
     *
     * Note the intentional asymmetry: a null amount is treated as a full refund
     * only by the gateway-refund path. This is preserved, not normalised.
     *
     * @param  float|int|string|null  $requestedAmount  Null means "no amount supplied".
     * @param  float|int|string       $paymentAmount    The recorded payment amount.
     */
    public static function isFullRefund($requestedAmount, $paymentAmount): bool
    {
        if ($requestedAmount === null) {
            return true;
        }

        return ((float) $requestedAmount) >= ((float) $paymentAmount);
    }
}
