<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\User;

/**
 * FirstBookingDiscountService
 *
 * Centralized service for:
 * - Eligibility check (user authenticated, no completed bookings)
 * - Discount calculation (percentage-based, capped at max amount)
 * - Discount validation (server-side source of truth)
 *
 * All values come from settings. Nothing is hardcoded.
 */
class FirstBookingDiscountService
{
    protected SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * The booking statuses that count as 'completed' for eligibility purposes.
     * A user is ineligible if they already have a booking in any of these statuses.
     */
    public static function eligibleStatuses(): array
    {
        return [
            Booking::STATUS_APPROVED,
            Booking::STATUS_SCHEDULED,
            Booking::STATUS_RUNNING,
            Booking::STATUS_COMPLETED,
            Booking::STATUS_PENDING_APPROVAL,
        ];
    }

    /**
     * Check if the first booking discount feature is enabled in settings.
     */
    public function isEnabled(): bool
    {
        return $this->settingsService->getBool('pricing', 'first_booking_discount_enabled', true);
    }

    /**
     * Get the discount percentage from settings.
     */
    public function getDiscountPercentage(): float
    {
        return (float) $this->settingsService->get('pricing', 'first_booking_discount_percentage', '15');
    }

    /**
     * Get the maximum discount amount (in INR) from settings.
     */
    public function getMaxDiscountAmount(): float
    {
        return (float) $this->settingsService->get('pricing', 'first_booking_discount_max_amount', '5000');
    }

    /**
     * Get settings snapshot for historical audit.
     */
    public function getSettingsSnapshot(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'discount_percentage' => $this->getDiscountPercentage(),
            'max_discount_amount' => $this->getMaxDiscountAmount(),
        ];
    }

    /**
     * Check if a user is eligible for the first booking discount.
     *
     * Eligibility criteria:
     * 1. Discount feature must be enabled in settings
     * 2. User must be authenticated
     * 3. User must NOT have any booking in an 'eligible' status
     *
     * @param User|null $user
     * @return bool
     */
    public function isEligible(?User $user): bool
    {
        // Must be enabled in settings
        if (!$this->isEnabled()) {
            return false;
        }

        // Must be authenticated
        if (!$user) {
            return false;
        }

        // Must NOT have any prior booking in eligible statuses
        $hasPriorBooking = Booking::where('user_id', $user->id)
            ->whereIn('status', self::eligibleStatuses())
            ->exists();

        return !$hasPriorBooking;
    }

    /**
     * Calculate the first booking discount for a given amount.
     *
     * Formula:
     * - Apply discount_percentage to the eligible amount
     * - Cap at max_discount_amount
     *
     * @param float $amount  The subtotal/eligible amount (in INR)
     * @return float         The discount amount (in INR)
     */
    public function calculateDiscount(float $amount): float
    {
        $percentage = $this->getDiscountPercentage();
        $maxAmount = $this->getMaxDiscountAmount();

        $rawDiscount = $amount * ($percentage / 100.0);

        return round(min($rawDiscount, $maxAmount), 2);
    }

    /**
     * Validate eligibility and calculate discount in one call.
     * Used by pricing preview and booking creation APIs.
     *
     * @param User|null $user
     * @param float $amount  The amount to apply discount to (in INR)
     * @return array{eligible: bool, amount: float, percentage: float, max_amount: float}
     */
    public function validateAndCalculate(?User $user, float $amount): array
    {
        $eligible = $this->isEligible($user);
        \Log::info('Eligible : ' . $eligible . ' User ID : ' . $user->id . ' Amount : ' . $amount . 'discount_percentage : ');

        return [
            'eligible' => $eligible,
            'amount' => $eligible ? $this->calculateDiscount($amount) : 0.00,
            'percentage' => $this->getDiscountPercentage(),
            'max_amount' => $this->getMaxDiscountAmount(),
            'settings_snapshot' => $this->getSettingsSnapshot(),
        ];
    }
}
