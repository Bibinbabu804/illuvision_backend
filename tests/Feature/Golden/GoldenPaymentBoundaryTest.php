<?php

namespace Tests\Feature\Golden;

use App\Application\Payment\UseCases\CreateOfflinePaymentUseCase;
use App\Application\Payment\UseCases\CreatePaymentOrderUseCase;
use App\Application\Payment\UseCases\GetPaymentStatusUseCase;
use App\Application\Payment\UseCases\RefundPaymentUseCase;
use App\Application\Payment\UseCases\VerifyPaymentUseCase;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 0.9 — Payment boundary composition regression.
 *
 * Guards the extraction itself: the container must resolve the new boundary
 * collaborators, the adapter must not reintroduce a vendor SDK dependency, and
 * a refund failure must still roll its transaction back.
 */
class GoldenPaymentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(array $overrides = []): Booking
    {
        $user = User::factory()->create();

        return Booking::create(array_merge([
            'user_id' => $user->id,
            'campaign_title' => 'Boundary Test Campaign',
            'title' => 'Boundary Test Campaign',
            'advertiser_name' => 'Boundary Advertiser',
            'advertiser_email' => 'boundary@advertiser.test',
            'start_time' => Carbon::now()->addDays(2),
            'end_time' => Carbon::now()->addDays(4),
            'final_amount' => 2000.00,
            'price' => 2000.00,
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ], $overrides));
    }

    public function test_container_resolves_every_payment_boundary_collaborator(): void
    {
        foreach ([
            PaymentRepositoryInterface::class,
            CreatePaymentOrderUseCase::class,
            VerifyPaymentUseCase::class,
            GetPaymentStatusUseCase::class,
            CreateOfflinePaymentUseCase::class,
            RefundPaymentUseCase::class,
        ] as $abstract) {
            $this->assertInstanceOf(
                $abstract,
                app($abstract),
                "Container failed to resolve {$abstract}"
            );
        }
    }

    public function test_payment_repository_resolves_to_eloquent_adapter(): void
    {
        $this->assertInstanceOf(
            \App\Infrastructure\Payments\Persistence\EloquentPaymentRepository::class,
            app(PaymentRepositoryInterface::class)
        );
    }

    public function test_gateway_contract_resolves_only_to_an_infrastructure_adapter(): void
    {
        $gateway = app(PaymentGatewayInterface::class);

        // The neutral interface is *expected* to resolve to the vendor adapter;
        // the boundary rule is that the adapter must live in infrastructure.
        $this->assertInstanceOf(PaymentGatewayInterface::class, $gateway);

        $reflection = new \ReflectionClass($gateway);
        $this->assertStringContainsString(
            'Infrastructure',
            str_replace('\\', '/', $reflection->getFileName()),
            'The gateway adapter escaped the infrastructure layer.'
        );

        $this->assertTrue(
            $reflection->implementsInterface(PaymentGatewayInterface::class)
        );
    }

    public function test_no_vendor_sdk_import_remains_in_services_or_domain(): void
    {
        $leaks = [];
        $roots = [app_path('Services'), app_path('Domain'), app_path('Application')];

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if (str_contains($contents, 'Razorpay\\Api\\Api')
                    || str_contains($contents, 'new Api(')) {
                    $leaks[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $leaks, 'Vendor SDK leaked out of infrastructure.');
    }

    public function test_payment_repository_preserves_pending_lookup_semantics(): void
    {
        $booking = $this->makeBooking();
        $repository = app(PaymentRepositoryInterface::class);

        $pending = $repository->create([
            'booking_id' => $booking->id,
            'razorpay_order_id' => 'order_boundary_1',
            'amount' => 2000.00,
            'currency' => 'INR',
            'payment_status' => 'pending',
        ]);

        $this->assertTrue($pending->exists);
        $this->assertSame(
            $pending->id,
            $repository->findPendingForBooking($booking)->id
        );
        $this->assertSame(
            $pending->id,
            $repository->findByGatewayOrderId('order_boundary_1')->id
        );
    }

    public function test_gateway_order_lookup_still_throws_when_missing(): void
    {
        $repository = app(PaymentRepositoryInterface::class);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $repository->findOrFailByGatewayOrderId('order_does_not_exist');
    }

    public function test_refund_failure_rolls_back_partial_refund_rows(): void
    {
        $booking = $this->makeBooking();

        $payment = app(PaymentRepositoryInterface::class)->create([
            'booking_id' => $booking->id,
            'razorpay_order_id' => 'order_refund_rollback',
            'razorpay_payment_id' => 'pay_refund_rollback',
            'amount' => 5000.00,
            'currency' => 'INR',
            'payment_status' => 'paid',
        ]);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('refund')
            ->willThrowException(new \RuntimeException('Gateway rejected the refund'));

        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        $refundsBefore = Refund::count();

        $admin = User::factory()->create();

        try {
            app(RefundPaymentUseCase::class)->execute($payment, 100.00, 'rollback probe', $admin->id);
            $this->fail('Expected the gateway failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Gateway rejected the refund', $e->getMessage());
        }

        $this->assertSame($refundsBefore, Refund::count(), 'Refund row survived a failed transaction.');
        $this->assertNull(
            $payment->fresh()->refund_status,
            'Payment refund_status was mutated outside the transaction.'
        );
    }

    public function test_recorded_refund_writes_row_log_and_partial_status(): void
    {
        $booking = $this->makeBooking();

        $payment = app(PaymentRepositoryInterface::class)->create([
            'booking_id' => $booking->id,
            'razorpay_order_id' => 'order_refund_ok',
            'razorpay_payment_id' => 'pay_refund_ok',
            'amount' => 5000.00,
            'currency' => 'INR',
            'payment_status' => 'paid',
        ]);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('refund')->willReturn([
            'id' => 'rfnd_boundary_1',
            'status' => 'processed',
            'amount' => 1000,
        ]);

        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        $admin = User::factory()->create();

        $refund = app(RefundPaymentUseCase::class)->execute($payment, 100.00, 'partial adjustment', $admin->id);

        $this->assertNotNull($refund->id);
        $this->assertSame('processed', $refund->status);

        // `refunds.amount` is persisted in rupees (decimal:2), matching legacy
        // behaviour — only the *gateway payload* is converted to paise.
        $this->assertSame(100.00, (float) $refund->amount);
        $this->assertDatabaseHas('refunds', [
            'id' => $refund->id,
            'payment_id' => $payment->id,
        ]);

        $payment->refresh();
        $this->assertSame('partially_refunded', $payment->payment_status);
        $this->assertSame('processed', $payment->refund_status);

        // The booking must not be flipped to refunded on a partial refund.
        $this->assertNotSame(Booking::STATUS_REFUNDED, $booking->fresh()->status);
    }

    public function test_offline_payment_use_case_records_without_gateway(): void
    {
        $booking = $this->makeBooking(['status' => Booking::STATUS_PENDING_APPROVAL]);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects($this->never())->method('createOrder');
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        $payment = app(CreateOfflinePaymentUseCase::class)->execute($booking, [
            'payment_mode' => 'bank_transfer',
            'amount' => 2000,
            'reference' => 'UTR-BOUNDARY-1',
            'notes' => 'boundary probe',
        ], User::factory()->create());

        $this->assertNotNull($payment->id);
        $this->assertSame($booking->id, $payment->booking_id);
        $this->assertSame('INR', $payment->currency);
    }

    public function test_payment_status_projection_requires_a_booking(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(GetPaymentStatusUseCase::class)->execute(999999);
    }

    public function test_payment_status_projection_reports_latest_payment(): void
    {
        $booking = $this->makeBooking();
        $repository = app(PaymentRepositoryInterface::class);

        $older = $repository->create([
            'booking_id' => $booking->id,
            'razorpay_order_id' => 'order_status_1',
            'amount' => 2000.00,
            'currency' => 'INR',
            'payment_status' => 'pending',
        ]);

        // `latest()` orders on created_at, and created_at is not mass-assignable,
        // so the backdating must go through a direct attribute write.
        $older->created_at = Carbon::now()->subHour();
        $older->save();

        $latest = $repository->create([
            'booking_id' => $booking->id,
            'razorpay_order_id' => 'order_status_2',
            'razorpay_payment_id' => 'pay_status_2',
            'amount' => 2000.00,
            'currency' => 'INR',
            'payment_status' => 'completed',
        ]);

        $status = app(GetPaymentStatusUseCase::class)->execute($booking->id);

        $this->assertSame($booking->id, $status['booking_id']);
        $this->assertSame($booking->status, $status['booking_status']);
        $this->assertNotNull($status['payment']);
        $this->assertSame($latest->razorpay_payment_id, $status['payment']['razorpay_payment_id']);
        $this->assertSame($latest->payment_status, $status['payment']['payment_status']);
    }

    public function test_payment_transaction_rollback_is_observable(): void
    {
        $booking = $this->makeBooking();

        try {
            DB::transaction(function () use ($booking) {
                app(PaymentRepositoryInterface::class)->create([
                    'booking_id' => $booking->id,
                    'razorpay_order_id' => 'order_rollback_probe',
                    'amount' => 10.00,
                    'currency' => 'INR',
                    'payment_status' => 'pending',
                ]);

                throw new \RuntimeException('forced rollback');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertDatabaseMissing('payments', [
            'razorpay_order_id' => 'order_rollback_probe',
        ]);
    }
}
