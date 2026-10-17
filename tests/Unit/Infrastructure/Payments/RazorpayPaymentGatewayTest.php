<?php

namespace Tests\Unit\Infrastructure\Payments;

use App\Infrastructure\Payments\Razorpay\RazorpayPaymentGateway;
use Razorpay\Api\Api;
use Tests\TestCase;

class RazorpayPaymentGatewayTest extends TestCase
{
    public function test_verify_signature_delegates_to_razorpay_utility(): void
    {
        $mockApi = $this->createMock(Api::class);
        $mockUtility = new class {
            public bool $called = false;
            public function verifyPaymentSignature(array $attrs): void {
                $this->called = true;
            }
        };

        $mockApi->utility = $mockUtility;

        $gateway = new RazorpayPaymentGateway($mockApi);
        $result = $gateway->verifySignature('order_123', 'pay_456', 'sig_789');

        $this->assertTrue($result);
        $this->assertTrue($mockUtility->called);
    }

    public function test_verify_signature_returns_false_on_exception(): void
    {
        $mockApi = $this->createMock(Api::class);
        $mockUtility = new class {
            public function verifyPaymentSignature(array $attrs): void {
                throw new \Exception('Signature mismatch');
            }
        };

        $mockApi->utility = $mockUtility;

        $gateway = new RazorpayPaymentGateway($mockApi);
        $result = $gateway->verifySignature('order_123', 'pay_456', 'bad_sig');

        $this->assertFalse($result);
    }
}
