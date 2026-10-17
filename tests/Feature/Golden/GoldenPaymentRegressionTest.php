<?php

namespace Tests\Feature\Golden;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GoldenPaymentRegressionTest extends TestCase
{
    use RefreshDatabase, GoldenTestSetupTrait;

    protected PaymentService $paymentService;
    protected string $razorpaySecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupGoldenEnvironment();
        Event::fake();

        // Ensure a deterministic test secret
        $this->razorpaySecret = 'test_secret_key_12345';
        Config::set('services.razorpay.key', 'rzp_test_key_12345');
        Config::set('services.razorpay.secret', $this->razorpaySecret);

        $this->paymentService = app(PaymentService::class);
    }

    /**
     * Test 1: Signature Verification - Valid vs Invalid Signature
     */
    public function test_signature_verification_valid_and_invalid(): void
    {
        $orderId = 'order_valid_123';
        $paymentId = 'pay_valid_456';

        // Valid signature generated using HMAC-SHA256
        $validSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->razorpaySecret);
        $invalidSignature = 'bad_signature_abc123';

        $this->assertTrue(
            $this->paymentService->verifySignature($orderId, $paymentId, $validSignature),
            'Valid HMAC SHA256 signature must be verified successfully'
        );

        $this->assertFalse(
            $this->paymentService->verifySignature($orderId, $paymentId, $invalidSignature),
            'Invalid signature must fail verification'
        );
    }

    /**
     * Test 2: Full Payment Confirmation flow with valid signature
     */
    public function test_verify_and_confirm_payment_successful_state_transition(): void
    {
        $booking = Booking::create([
            'user_id' => $this->customerUser->id,
            'campaign_title' => 'Payment Verification Campaign',
            'title' => 'Payment Verification Campaign',
            'advertiser_name' => $this->customerUser->name,
            'advertiser_email' => $this->customerUser->email,
            'start_time' => Carbon::now()->addDays(2),
            'end_time' => Carbon::now()->addDays(4),
            'subtotal' => 2000.00,
            'final_amount' => 2000.00,
            'price' => 2000.00,
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        $orderId = 'order_confirm_001';
        $paymentId = 'pay_confirm_002';
        $validSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->razorpaySecret);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'razorpay_order_id' => $orderId,
            'amount' => 2000.00,
            'currency' => 'INR',
            'payment_status' => 'pending',
        ]);

        $confirmedPayment = $this->paymentService->verifyAndConfirmPayment(
            $orderId,
            $paymentId,
            $validSignature
        );

        $this->assertEquals('paid', $confirmedPayment->payment_status);
        $this->assertNotNull($confirmedPayment->paid_at);
        $this->assertEquals($paymentId, $confirmedPayment->razorpay_payment_id);
        $this->assertEquals($validSignature, $confirmedPayment->razorpay_signature);

        $booking->refresh();
        $this->assertEquals(Booking::STATUS_PENDING_APPROVAL, $booking->status);
    }

    /**
     * Test 3: Invalid signature fails verification and transitions status to payment_failed
     */
    public function test_verify_and_confirm_payment_invalid_signature_marks_failed(): void
    {
        $booking = Booking::create([
            'user_id' => $this->customerUser->id,
            'campaign_title' => 'Failed Payment Campaign',
            'title' => 'Failed Payment Campaign',
            'advertiser_name' => $this->customerUser->name,
            'advertiser_email' => $this->customerUser->email,
            'start_time' => Carbon::now()->addDays(2),
            'end_time' => Carbon::now()->addDays(4),
            'subtotal' => 1500.00,
            'final_amount' => 1500.00,
            'price' => 1500.00,
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        $orderId = 'order_failed_001';
        $paymentId = 'pay_failed_002';
        $badSignature = 'bad_sig_999';

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'razorpay_order_id' => $orderId,
            'amount' => 1500.00,
            'currency' => 'INR',
            'payment_status' => 'pending',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Payment signature verification failed.');

        try {
            $this->paymentService->verifyAndConfirmPayment($orderId, $paymentId, $badSignature);
        } finally {
            $payment->refresh();
            $booking->refresh();

            // Crucial Characterization Finding:
            // Because throw new \Exception() occurs inside DB::transaction(),
            // MySQL rolls back the status update, leaving the payment and booking as pending!
            $this->assertEquals('pending', $payment->payment_status, 'DB::transaction rollback reverts payment_status update');
            $this->assertEquals(Booking::STATUS_PAYMENT_PENDING, $booking->status, 'DB::transaction rollback reverts booking status update');
        }
    }

    /**
     * Test 4: Payment Idempotency - Duplicate verification request throws exception
     */
    public function test_duplicate_verification_request_is_rejected(): void
    {
        $booking = Booking::create([
            'user_id' => $this->customerUser->id,
            'campaign_title' => 'Idempotency Test Campaign',
            'title' => 'Idempotency Test Campaign',
            'advertiser_name' => $this->customerUser->name,
            'advertiser_email' => $this->customerUser->email,
            'start_time' => Carbon::now()->addDays(2),
            'end_time' => Carbon::now()->addDays(4),
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'price' => 1000.00,
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        $orderId = 'order_idem_001';
        $paymentId = 'pay_idem_002';
        $validSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->razorpaySecret);

        Payment::create([
            'booking_id' => $booking->id,
            'razorpay_order_id' => $orderId,
            'amount' => 1000.00,
            'currency' => 'INR',
            'payment_status' => 'pending',
        ]);

        // First verification succeeds
        $this->paymentService->verifyAndConfirmPayment($orderId, $paymentId, $validSignature);

        // Second verification must be rejected with 'Payment already verified for this order.'
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Payment already verified for this order.');

        $this->paymentService->verifyAndConfirmPayment($orderId, $paymentId, $validSignature);
    }
}
