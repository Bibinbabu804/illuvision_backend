<?php

namespace Tests\Feature\Golden;

use App\Application\Financial\UseCases\DistributeBookingRevenueUseCase;
use App\Application\Financial\UseCases\GenerateProviderSettlementUseCase;
use App\Application\Financial\UseCases\RecordReferralAttributionUseCase;
use App\Domain\Financial\Contracts\PartnerCommissionRepositoryInterface;
use App\Domain\Financial\Contracts\SettlementRepositoryInterface;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\PartnerCommission;
use App\Models\ReferralUsage;
use App\Models\Screen;
use App\Models\ScreenProviderSettlement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Phase 0.10 — Financial boundary composition regression.
 *
 * Guards the extraction itself: the financial collaborators must resolve, the
 * settlement eligibility predicate must keep all four of its filters, snapshot
 * values must remain the settlement source, and commission pessimistic locking
 * must survive the repository move.
 */
class GoldenFinancialBoundaryTest extends TestCase
{
    use RefreshDatabase, GoldenTestSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupGoldenEnvironment();
        Event::fake();
    }

    private function makeBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'user_id' => $this->customerUser->id,
            'campaign_title' => 'Financial Boundary Campaign',
            'title' => 'Financial Boundary Campaign',
            'advertiser_name' => 'Boundary Advertiser',
            'advertiser_email' => 'boundary@advertiser.test',
            'advertisement_category_id' => $this->adCategory->id,
            'start_time' => Carbon::now()->subDays(10),
            'end_time' => Carbon::now()->subDays(5),
            'final_amount' => 1500.00,
            'price' => 1500.00,
            'status' => Booking::STATUS_COMPLETED,
        ], $overrides));
    }

    private function period(): array
    {
        return [Carbon::now()->subDays(30)->startOfDay(), Carbon::now()->startOfDay()];
    }

    public function test_container_resolves_every_financial_boundary_collaborator(): void
    {
        foreach ([
            SettlementRepositoryInterface::class,
            PartnerCommissionRepositoryInterface::class,
            DistributeBookingRevenueUseCase::class,
            GenerateProviderSettlementUseCase::class,
            RecordReferralAttributionUseCase::class,
        ] as $abstract) {
            $this->assertInstanceOf($abstract, app($abstract), "Failed to resolve {$abstract}");
        }
    }

    public function test_financial_interfaces_resolve_to_eloquent_adapters(): void
    {
        $this->assertInstanceOf(
            \App\Infrastructure\Financial\Persistence\EloquentSettlementRepository::class,
            app(SettlementRepositoryInterface::class)
        );
        $this->assertInstanceOf(
            \App\Infrastructure\Financial\Persistence\EloquentPartnerCommissionRepository::class,
            app(PartnerCommissionRepositoryInterface::class)
        );
    }

    public function test_settleable_booking_status_set_is_unchanged(): void
    {
        $this->assertSame([
            Booking::STATUS_APPROVED,
            Booking::STATUS_SCHEDULED,
            Booking::STATUS_RUNNING,
            Booking::STATUS_COMPLETED,
        ], \App\Infrastructure\Financial\Persistence\EloquentSettlementRepository::SETTLEABLE_BOOKING_STATUSES);
    }

    public function test_revenue_distribution_writes_snapshot_for_each_screen(): void
    {
        $booking = $this->makeBooking(['final_amount' => 1000.00, 'price' => 1000.00]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenProvider->id,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenIlluvision->id,
        ]);

        app(DistributeBookingRevenueUseCase::class)->execute($booking);

        $providerScreen = BookingScreen::where('booking_id', $booking->id)
            ->where('screen_id', $this->fixedScreenProvider->id)
            ->first();
        $ownedScreen = BookingScreen::where('booking_id', $booking->id)
            ->where('screen_id', $this->fixedScreenIlluvision->id)
            ->first();

        // 1000 / 2 screens = 500 gross each.
        $this->assertSame(500.00, (float) $providerScreen->gross_revenue);
        $this->assertSame(100.00, (float) $providerScreen->platform_share);   // 20%
        $this->assertSame(400.00, (float) $providerScreen->provider_share);  // 80%

        // Illuvision-owned screens are 100/0.
        $this->assertSame(500.00, (float) $ownedScreen->platform_share);
        $this->assertSame(0.00, (float) $ownedScreen->provider_share);
        $this->assertSame(100.00, (float) $ownedScreen->illuvision_percentage);
        $this->assertSame(0.00, (float) $ownedScreen->screen_provider_percentage);

        $this->assertSame('pending', $providerScreen->settlement_status);
    }

    public function test_distribution_is_skipped_for_zero_screen_bookings(): void
    {
        $booking = $this->makeBooking();

        // No booking_screen rows — the legacy divisibility guard must hold.
        app(DistributeBookingRevenueUseCase::class)->execute($booking);

        $this->assertSame(0, BookingScreen::where('booking_id', $booking->id)->count());
    }

    public function test_settlement_excludes_illuvision_owned_screens(): void
    {
        $booking = $this->makeBooking();

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenProvider->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'settlement_status' => 'pending',
            'gross_revenue' => 1000.00,
            'platform_share' => 200.00,
            'provider_share' => 800.00,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenIlluvision->id,
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'screen_provider_id' => null,
            'settlement_status' => 'pending',
            'gross_revenue' => 5000.00,
            'platform_share' => 5000.00,
            'provider_share' => 0.00,
        ]);

        [$start, $end] = $this->period();

        $eligible = app(SettlementRepositoryInterface::class)
            ->findEligibleBookingScreens($this->providerAlpha->id, $start, $end);

        $this->assertCount(1, $eligible);
        $this->assertSame(1000.00, (float) $eligible->first()->gross_revenue);
    }

    public function test_settlement_excludes_non_settleable_booking_statuses(): void
    {
        $booking = $this->makeBooking(['status' => Booking::STATUS_PENDING_APPROVAL]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenProvider->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'settlement_status' => 'pending',
            'gross_revenue' => 1000.00,
            'platform_share' => 200.00,
            'provider_share' => 800.00,
        ]);

        [$start, $end] = $this->period();

        $eligible = app(SettlementRepositoryInterface::class)
            ->findEligibleBookingScreens($this->providerAlpha->id, $start, $end);

        $this->assertCount(0, $eligible);
    }

    public function test_settlement_excludes_already_settled_screens(): void
    {
        $booking = $this->makeBooking();

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenProvider->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'settlement_status' => 'settled',
            'gross_revenue' => 1000.00,
            'platform_share' => 200.00,
            'provider_share' => 800.00,
        ]);

        [$start, $end] = $this->period();

        $eligible = app(SettlementRepositoryInterface::class)
            ->findEligibleBookingScreens($this->providerAlpha->id, $start, $end);

        $this->assertCount(0, $eligible);
    }

    public function test_settlement_excludes_screens_outside_the_period(): void
    {
        $booking = $this->makeBooking([
            'start_time' => Carbon::now()->subDays(200),
            'end_time' => Carbon::now()->subDays(195),
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenProvider->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'settlement_status' => 'pending',
            'gross_revenue' => 1000.00,
            'platform_share' => 200.00,
            'provider_share' => 800.00,
        ]);

        [$start, $end] = $this->period();

        $eligible = app(SettlementRepositoryInterface::class)
            ->findEligibleBookingScreens($this->providerAlpha->id, $start, $end);

        $this->assertCount(0, $eligible);
    }

    public function test_generated_settlement_sums_snapshots_not_live_percentages(): void
    {
        $booking = $this->makeBooking();

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenProvider->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'settlement_status' => 'pending',
            'gross_revenue' => 1000.00,
            'platform_share' => 333.33,
            'provider_share' => 666.67,
        ]);
        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->mobileFleetScreen->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'settlement_status' => 'pending',
            'gross_revenue' => 500.00,
            'platform_share' => 111.11,
            'provider_share' => 388.89,
        ]);

        // A live percentage that would contradict the snapshots if it were used.
        $this->providerAlpha->update(['screen_provider_percentage' => 99.00]);

        $settlement = app(GenerateProviderSettlementUseCase::class)->execute(
            $this->providerAlpha->id,
            Carbon::now()->subDays(30)->toDateString(),
            Carbon::now()->toDateString(),
            $this->adminUser
        );

        $this->assertSame(1500.00, (float) $settlement->gross_revenue);
        $this->assertSame(444.44, (float) $settlement->platform_revenue);
        $this->assertSame(1055.56, (float) $settlement->provider_revenue);
        $this->assertSame(1, $settlement->booking_count);
        $this->assertSame(2, $settlement->screen_count);
        $this->assertSame(ScreenProviderSettlement::STATUS_GENERATED, $settlement->status);
    }

    public function test_settlement_generation_marks_screens_settled(): void
    {
        $booking = $this->makeBooking();

        $screen = BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenProvider->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'settlement_status' => 'pending',
            'gross_revenue' => 1000.00,
            'platform_share' => 200.00,
            'provider_share' => 800.00,
        ]);

        $settlement = app(GenerateProviderSettlementUseCase::class)->execute(
            $this->providerAlpha->id,
            Carbon::now()->subDays(30)->toDateString(),
            Carbon::now()->toDateString(),
            $this->adminUser
        );

        $screen->refresh();
        $this->assertSame('settled', $screen->settlement_status);
        $this->assertSame($settlement->id, $screen->settlement_id);
        $this->assertNotNull($screen->settled_at);

        $this->assertDatabaseHas('screen_provider_activities', [
            'screen_provider_id' => $this->providerAlpha->id,
            'type' => 'settlement_generated',
        ]);
    }

    public function test_booking_scoped_settlement_generation_ignores_the_period_filter(): void
    {
        // A booking far outside the requested period is still settleable when
        // explicitly targeted by id — this is the legacy second branch.
        $booking = $this->makeBooking([
            'start_time' => Carbon::now()->subDays(400),
            'end_time' => Carbon::now()->subDays(395),
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreenProvider->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'settlement_status' => 'pending',
            'gross_revenue' => 1000.00,
            'platform_share' => 200.00,
            'provider_share' => 800.00,
        ]);

        $settlement = app(GenerateProviderSettlementUseCase::class)->execute(
            $this->providerAlpha->id,
            Carbon::now()->subDays(30)->toDateString(),
            Carbon::now()->toDateString(),
            $this->adminUser,
            $booking->id
        );

        $this->assertSame(1000.00, (float) $settlement->gross_revenue);
    }

    public function test_settlement_generation_without_eligible_screens_still_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No eligible booking screens found for the given period.');

        app(GenerateProviderSettlementUseCase::class)->execute(
            $this->providerAlpha->id,
            Carbon::now()->subDays(30)->toDateString(),
            Carbon::now()->toDateString(),
            $this->adminUser
        );
    }

    public function test_commission_repository_preserves_pessimistic_locking(): void
    {
        $usage = ReferralUsage::create([
            'referral_code_id' => $this->referralCode->id,
            'booking_id' => null,
            'user_id' => $this->customerUser->id,
            'discount_amount' => 0,
            'commission_amount' => 100.00,
            'status' => 'pending',
        ]);

        $commission = PartnerCommission::create([
            'partner_id' => $this->partner->id,
            'referral_usage_id' => $usage->id,
            'amount' => 100.00,
            'status' => PartnerCommission::STATUS_PENDING,
        ]);

        // The locked read must run inside a transaction, and must not throw.
        $locked = DB::transaction(function () use ($commission) {
            return app(PartnerCommissionRepositoryInterface::class)
                ->findOrFailForUpdate($commission->id);
        });

        $this->assertSame($commission->id, $locked->id);
    }

    public function test_commission_repository_lookup_helpers(): void
    {
        $usage = ReferralUsage::create([
            'referral_code_id' => $this->referralCode->id,
            'booking_id' => null,
            'user_id' => $this->customerUser->id,
            'discount_amount' => 0,
            'commission_amount' => 250.00,
            'status' => 'pending',
        ]);

        $commission = PartnerCommission::create([
            'partner_id' => $this->partner->id,
            'referral_usage_id' => $usage->id,
            'amount' => 250.00,
            'status' => PartnerCommission::STATUS_PENDING,
        ]);

        $otherUsage = ReferralUsage::create([
            'referral_code_id' => $this->referralCode->id,
            'booking_id' => null,
            'user_id' => $this->customerUser->id,
            'discount_amount' => 0,
            'commission_amount' => 10.00,
            'status' => 'pending',
        ]);

        $repository = app(PartnerCommissionRepositoryInterface::class);

        $this->assertSame($commission->id, $repository->find($commission->id)->id);
        $this->assertSame(
            $commission->id,
            $repository->findForReferralUsage($usage->id)->id
        );
        $this->assertNull($repository->findForReferralUsage($otherUsage->id));
    }
}
