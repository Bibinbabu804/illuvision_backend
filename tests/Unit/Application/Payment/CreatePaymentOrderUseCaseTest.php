<?php

namespace Tests\Unit\Application\Payment;

use App\Application\Payment\UseCases\CreatePaymentOrderUseCase;
use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatePaymentOrderUseCaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Booking $booking;
    protected PaymentRepositoryInterface $paymentRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->booking = Booking::create([
            'user_id' => $this->user->id,
            'campaign_title' => 'Use Case Test Campaign',
            'title' => 'Use Case Test Campaign',
            'advertiser_name' => 'Test Advertiser',
            'advertiser_email' => 'test@advertiser.com',
            'start_time' => Carbon::now()->addDays(2),
            'end_time' => Carbon::now()->addDays(4),
            'final_amount' => 1500.00,
            'price' => 1500.00,
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        $this->paymentRepository = app(PaymentRepositoryInterface::class);
    }

    public function test_existing_pending_payment_returned_without_calling_service_create(): void
    {
        Payment::create([
            'booking_id' => $this->booking->id,
            'razorpay_order_id' => 'order_existing_888',
            'amount' => 1500.00,
            'currency' => 'INR',
            'payment_status' => 'pending',
        ]);

        $mockPaymentService = $this->createMock(PaymentService::class);
        $mockPaymentService->expects($this->never())->method('createOrder');

        $useCase = new CreatePaymentOrderUseCase($mockPaymentService, $this->paymentRepository);
        $result = $useCase->execute($this->booking);

        $this->assertTrue($result['is_existing']);
        $this->assertEquals('order_existing_888', $result['razorpay_order_id']);
        $this->assertEquals(150000, $result['amount']);
        $this->assertEquals('INR', $result['currency']);
    }

    public function test_new_payment_delegates_to_payment_service(): void
    {
        $createdPayment = new Payment([
            'booking_id' => $this->booking->id,
            'razorpay_order_id' => 'order_fresh_777',
            'amount' => 1500.00,
            'currency' => 'INR',
            'payment_status' => 'pending',
        ]);
        $createdPayment->setRelation('booking', $this->booking);

        $mockPaymentService = $this->createMock(PaymentService::class);
        $mockPaymentService->expects($this->once())
            ->method('createOrder')
            ->with($this->booking)
            ->willReturn($createdPayment);

        $useCase = new CreatePaymentOrderUseCase($mockPaymentService, $this->paymentRepository);
        $result = $useCase->execute($this->booking);

        $this->assertFalse($result['is_existing']);
        $this->assertEquals('order_fresh_777', $result['razorpay_order_id']);
        $this->assertEquals(150000, $result['amount']);
        $this->assertEquals('INR', $result['currency']);
    }
}
