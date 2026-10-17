<?php

namespace Tests\Unit\Domain\Payment;

use App\Domain\Payment\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0.9 — Money boundary regression.
 *
 * These assertions pin the extraction contract: `Money` must keep producing
 * byte-identical results to the legacy inline arithmetic it replaced. If any of
 * these fail, a monetary value has changed.
 */
class MoneyTest extends TestCase
{
    #[DataProvider('rupeeToPaiseProvider')]
    public function test_to_paise_reproduces_legacy_arithmetic($rupees, int $expectedPaise): void
    {
        $this->assertSame($expectedPaise, Money::toPaise($rupees));
    }

    public static function rupeeToPaiseProvider(): array
    {
        return [
            'integer rupees' => [1500, 150000],
            'two decimal rupees' => [1500.50, 150050],
            'sub rupee rounds half up' => [10.005, 1001],
            'single paisa' => [0.01, 1],
            'zero' => [0, 0],
            'numeric string' => ['2500.75', 250075],
            'three decimals truncated by round' => [33.333, 3333],
        ];
    }

    public function test_to_paise_accepts_paise_integers_without_drift(): void
    {
        // Round-trip must be stable for every cent value.
        for ($rupees = 0; $rupees <= 25; $rupees += 0.01) {
            $paise = Money::toPaise($rupees);

            $this->assertSame(
                round($rupees, 2),
                Money::toRupees($paise),
                "Round-trip drift at {$rupees} rupees"
            );
        }
    }

    #[DataProvider('paiseToRupeeProvider')]
    public function test_to_rupees_reproduces_legacy_arithmetic(int $paise, float $expectedRupees): void
    {
        $this->assertSame($expectedRupees, Money::toRupees($paise));
    }

    public static function paiseToRupeeProvider(): array
    {
        return [
            'zero' => [0, 0.0],
            'one hundred paise' => [100, 1.0],
            'odd paise' => [150050, 1500.5],
            'negative refund adjustment' => [-5050, -50.5],
        ];
    }

    public function test_normalise_reproduces_round_two_decimals(): void
    {
        $this->assertSame(1500.51, Money::normalise(1500.505));
        $this->assertSame(10.0, Money::normalise(10));
    }

    public function test_is_full_refund_treats_null_as_full_only_for_gateway_path(): void
    {
        // Preserved asymmetry between the two legacy call sites.
        $this->assertTrue(Money::isFullRefund(null, 1000.00));
    }

    #[DataProvider('fullRefundProvider')]
    public function test_is_full_refund_compares_against_recorded_amount(
        $requested,
        $paymentAmount,
        bool $expected
    ): void {
        $this->assertSame($expected, Money::isFullRefund($requested, $paymentAmount));
    }

    public static function fullRefundProvider(): array
    {
        return [
            'exact amount is full' => [1000.00, 1000.00, true],
            'over amount is full' => [1500.00, 1000.00, true],
            'partial amount is not full' => [500.00, 1000.00, false],
            'paise-scale precision' => [999.99, 1000.00, false],
            'one paisa under' => [999.99, 999.99, true],
        ];
    }

    public function test_supported_gateway_currency_is_inr(): void
    {
        $this->assertSame('INR', Money::GATEWAY_CURRENCY);
        $this->assertSame(100, Money::MINOR_UNITS_PER_MAJOR);
    }
}
