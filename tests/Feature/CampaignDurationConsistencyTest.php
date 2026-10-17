<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenProvider;
use App\Models\User;
use App\Services\ReportService;
use App\Services\RevenueDistributionService;
use App\Services\Campaign\ScreenAnalyticsService;
use App\Support\DurationFormatter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignDurationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $advertiser;
    protected Country $country;
    protected City $city;
    protected PricingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->advertiser = User::factory()->create(['role' => 'advertiser']);
        $this->country = Country::create(['name' => 'India', 'code' => 'IN', 'phone_code' => '91']);
        $state = \App\Models\State::create(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->city = City::create(['name' => 'Kochi', 'country_id' => $this->country->id, 'state_id' => $state->id]);
        $this->plan = PricingPlan::create([
            'name' => 'Minute Plan',
            'pricing_model' => 'dynamic',
            'base_price' => 1000,
            'screen_pricing_model' => 'fixed',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
        ]);
    }

    /**
     * Test centralized DurationFormatter with canonical test cases.
     */
    public function test_duration_formatter_canonical_cases()
    {
        $this->assertEquals('30 Minutes', DurationFormatter::formatMinutes(30));
        $this->assertEquals('45 Minutes', DurationFormatter::formatMinutes(45));
        $this->assertEquals('1 Hour', DurationFormatter::formatMinutes(60));
        $this->assertEquals('1 Hour 30 Minutes', DurationFormatter::formatMinutes(90));
        $this->assertEquals('2 Hours', DurationFormatter::formatMinutes(120));
        $this->assertEquals('2 Hours 15 Minutes', DurationFormatter::formatMinutes(135));
        $this->assertEquals('1 Day', DurationFormatter::formatMinutes(1440));
        $this->assertEquals('2 Days', DurationFormatter::formatMinutes(2880));
        $this->assertEquals('1 Month', DurationFormatter::formatMinutes(43200));
        $this->assertEquals('2 Months', DurationFormatter::formatMinutes(86400));
    }

    /**
     * Test Booking model accessors and JSON serialization.
     */
    public function test_booking_model_duration_accessors()
    {
        $booking30m = Booking::create([
            'user_id' => $this->advertiser->id,
            'pricing_plan_id' => $this->plan->id,
            'title' => 'Flash Sale 30m',
            'campaign_title' => 'Flash Sale 30m',
            'advertiser_name' => 'Store A',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'ad_frequency' => 'every_5_min',
            'price' => 500,
            'final_amount' => 500,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ]);

        $this->assertEquals(30, $booking30m->duration_minutes);
        $this->assertEquals('30 Minutes', $booking30m->duration_label);
        $this->assertLessThan(1, $booking30m->duration_days);

        $json = $booking30m->toArray();
        $this->assertArrayHasKey('duration_minutes', $json);
        $this->assertArrayHasKey('duration_days', $json);
        $this->assertArrayHasKey('duration_label', $json);
        $this->assertEquals(30, $json['duration_minutes']);
        $this->assertEquals('30 Minutes', $json['duration_label']);
    }

    /**
     * Test ScreenAnalyticsService campaignAnalytics minute accuracy.
     */
    public function test_screen_analytics_campaign_duration_consistency()
    {
        $screen = Screen::create([
            'name' => 'Lulu Mall Screen',
            'screen_code' => 'KOC-LUL-01',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Lulu Mall Edappally',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $b1 = Booking::create([
            'user_id' => $this->advertiser->id,
            'pricing_plan_id' => $this->plan->id,
            'title' => 'Morning 30m',
            'campaign_title' => 'Morning 30m',
            'advertiser_name' => 'Advertiser 1',
            'start_time' => '2026-08-28 09:00:00',
            'end_time' => '2026-08-28 09:30:00',
            'final_amount' => 300,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ]);
        $b1->created_at = '2026-08-28 08:00:00';
        $b1->save();

        BookingScreen::create([
            'booking_id' => $b1->id,
            'screen_id' => $screen->id,
            'quantity' => 1,
            'subtotal' => 300,
            'status' => 'approved',
        ]);

        $b2 = Booking::create([
            'user_id' => $this->advertiser->id,
            'pricing_plan_id' => $this->plan->id,
            'title' => 'Evening 90m',
            'campaign_title' => 'Evening 90m',
            'advertiser_name' => 'Advertiser 2',
            'start_time' => '2026-08-28 17:00:00',
            'end_time' => '2026-08-28 18:30:00',
            'final_amount' => 900,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ]);
        $b2->created_at = '2026-08-28 08:30:00';
        $b2->save();

        BookingScreen::create([
            'booking_id' => $b2->id,
            'screen_id' => $screen->id,
            'quantity' => 1,
            'subtotal' => 900,
            'status' => 'approved',
        ]);

        $analyticsService = app(ScreenAnalyticsService::class);
        $campaigns = $analyticsService->campaignAnalytics('2026-08-01', '2026-08-31');

        $this->assertCount(2, $campaigns);
        $campMap = collect($campaigns)->keyBy('title');

        $this->assertEquals(30, $campMap['Morning 30m']['duration_minutes']);
        $this->assertEquals('30 Minutes', $campMap['Morning 30m']['duration_label']);

        $this->assertEquals(90, $campMap['Evening 90m']['duration_minutes']);
        $this->assertEquals('1 Hour 30 Minutes', $campMap['Evening 90m']['duration_label']);
    }

    /**
     * Test ReportService average and total duration calculation in canonical minutes.
     */
    public function test_report_service_average_and_total_duration()
    {
        $screen = Screen::create([
            'name' => 'Forum Mall Screen',
            'screen_code' => 'KOC-FRM-01',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Forum Mall Maradu',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // 30m campaign
        $b1 = Booking::create([
            'user_id' => $this->advertiser->id,
            'pricing_plan_id' => $this->plan->id,
            'title' => 'Camp 30m',
            'campaign_title' => 'Camp 30m',
            'advertiser_name' => 'User 1',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'final_amount' => 300,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ]);
        $b1->created_at = '2026-08-28 08:00:00';
        $b1->save();
        BookingScreen::create(['booking_id' => $b1->id, 'screen_id' => $screen->id, 'quantity' => 1, 'subtotal' => 300, 'status' => 'approved']);

        // 60m campaign
        $b2 = Booking::create([
            'user_id' => $this->advertiser->id,
            'pricing_plan_id' => $this->plan->id,
            'title' => 'Camp 60m',
            'campaign_title' => 'Camp 60m',
            'advertiser_name' => 'User 2',
            'start_time' => '2026-08-28 11:00:00',
            'end_time' => '2026-08-28 12:00:00',
            'final_amount' => 600,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ]);
        $b2->created_at = '2026-08-28 08:15:00';
        $b2->save();
        BookingScreen::create(['booking_id' => $b2->id, 'screen_id' => $screen->id, 'quantity' => 1, 'subtotal' => 600, 'status' => 'approved']);

        // 90m campaign
        $b3 = Booking::create([
            'user_id' => $this->advertiser->id,
            'pricing_plan_id' => $this->plan->id,
            'title' => 'Camp 90m',
            'campaign_title' => 'Camp 90m',
            'advertiser_name' => 'User 3',
            'start_time' => '2026-08-28 13:00:00',
            'end_time' => '2026-08-28 14:30:00',
            'final_amount' => 900,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ]);
        $b3->created_at = '2026-08-28 08:30:00';
        $b3->save();
        BookingScreen::create(['booking_id' => $b3->id, 'screen_id' => $screen->id, 'quantity' => 1, 'subtotal' => 900, 'status' => 'approved']);

        $reportService = app(ReportService::class);
        $report = $reportService->getCampaignReport([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
        ]);

        $summary = $report['summary'];

        // Total: 30 + 60 + 90 = 180 minutes = 3 Hours
        $this->assertEquals(180, $summary['total_duration_minutes']);
        $this->assertEquals('3 Hours', $summary['total_duration_label']);

        // Average: 180 / 3 = 60 minutes = 1 Hour
        $this->assertEquals(60, $summary['average_duration_minutes']);
        $this->assertEquals('1 Hour', $summary['average_duration_label']);
    }

    /**
     * Test Screen Provider booking history duration formatting and financial snapshot preservation.
     */
    public function test_provider_booking_history_duration_and_financial_preservation()
    {
        $provider = ScreenProvider::create([
            'provider_name' => 'Apex Displays',
            'business_name' => 'Apex Media Ltd',
            'business_type' => 'company',
            'email' => 'apex@example.com',
            'phone' => '9876543210',
            'status' => 'approved',
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
        ]);

        $screen = Screen::create([
            'name' => 'Apex Marine Drive',
            'screen_code' => 'APX-MDR-01',
            'screen_type' => 'digital_billboard',
            'location_name' => 'Marine Drive Promenade',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $provider->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->plan->id,
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $booking = Booking::create([
            'user_id' => $this->advertiser->id,
            'pricing_plan_id' => $this->plan->id,
            'title' => 'Flash 30min',
            'campaign_title' => 'Flash 30min',
            'advertiser_name' => 'Fashion Hub',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'price' => 1000,
            'final_amount' => 1000,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ]);

        $bookingScreen = BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $screen->id,
            'quantity' => 1,
            'subtotal' => 1000,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $provider->id,
            'gross_revenue' => 1000,
            'platform_share' => 150,
            'provider_share' => 850,
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
            'settlement_status' => 'pending',
            'status' => 'approved',
        ]);

        $revDistService = app(RevenueDistributionService::class);
        $history = $revDistService->getProviderBookingHistory($provider->id);

        $this->assertNotEmpty($history->items());
        $item = $history->items()[0];

        $this->assertEquals(30, $item->duration_minutes);
        $this->assertEquals('30 Minutes', $item->duration_label);
        $this->assertEquals('30 Minutes', $item->duration);
        $this->assertEquals(850.0, (float) $item->provider_share);
        $this->assertEquals(150.0, (float) $item->platform_share);
    }
}
