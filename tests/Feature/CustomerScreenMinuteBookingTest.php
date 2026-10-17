<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\PricingPlan;
use App\Models\PricingDurationRule;
use App\Models\PricingScreenRule;
use App\Models\Screen;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerScreenMinuteBookingTest extends TestCase
{
    use RefreshDatabase;

    protected User $customer;
    protected Country $country;
    protected State $state;
    protected City $city;
    protected AdvertisementCategory $category;
    protected PricingPlan $minutePlan;
    protected Screen $fixedScreen;
    protected Screen $mobileFleet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create([
            'role' => 'user',
            'email' => 'customer@example.com',
        ]);

        $this->country = Country::create(['name' => 'India', 'code' => 'IN']);
        $this->state = State::create(['name' => 'Maharashtra', 'country_id' => $this->country->id]);
        $this->city = City::create(['name' => 'Mumbai', 'country_id' => $this->country->id, 'state_id' => $this->state->id]);

        $this->category = AdvertisementCategory::create([
            'name' => 'Automotive',
            'slug' => 'automotive',
            'status' => 'active',
        ]);

        // Pricing plan with 30-minute minimum duration and base_price 300
        $this->minutePlan = PricingPlan::create([
            'name' => '30 Min Standard Plan',
            'base_price' => 300.00,
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Minute',
            'is_default' => true,
            'is_active' => true,
        ]);

        PricingDurationRule::create([
            'pricing_plan_id' => $this->minutePlan->id,
            'minimum_duration' => 30,
            'maximum_duration' => null,
            'duration_unit' => 'minute',
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        PricingScreenRule::create([
            'pricing_plan_id' => $this->minutePlan->id,
            'minimum_screens' => 1,
            'maximum_screens' => null,
            'discount_type' => 'percentage',
            'discount_value' => 0,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $this->fixedScreen = Screen::create([
            'name' => 'Bandra LED Billboard',
            'screen_type' => 'digital_billboard',
            'asset_type' => 'fixed',
            'location_name' => 'Bandra West, Mumbai',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'status' => 'active',
            'booking_status' => 'available',
            'is_mobile' => false,
            'fleet_size' => 1,
            'slots' => 10,
        ]);

        $this->mobileFleet = Screen::create([
            'name' => 'Mumbai Bus Fleet A',
            'screen_type' => 'mobile_digital',
            'asset_type' => 'mobile',
            'location_name' => 'Mumbai Wide Fleet',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->minutePlan->id,
            'status' => 'active',
            'booking_status' => 'available',
            'is_mobile' => true,
            'fleet_size' => 5,
            'slots' => 10,
        ]);
    }

    public function test_customer_can_calculate_pricing_for_30_minute_campaign()
    {
        $payload = [
            'screen_ids' => [$this->fixedScreen->id],
            'start_date' => '2026-08-28',
            'end_date' => '2026-08-28',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
        ];

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/campaigns/calculate', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.campaign_summary.total_days', 1);
        $response->assertJsonPath('data.campaign_summary.daily_runtime.start_time', '10:00');
        $response->assertJsonPath('data.campaign_summary.daily_runtime.end_time', '10:30');
        $this->assertGreaterThan(0, $response->json('data.aggregated.final_total'));
    }

    public function test_customer_can_book_30_minute_campaign_successfully()
    {
        $pricingResponse = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/campaigns/calculate', [
                'screen_ids' => [$this->fixedScreen->id],
                'start_date' => '2026-08-28',
                'end_date' => '2026-08-28',
                'daily_start_time' => '10:00',
                'daily_end_time' => '10:30',
            ]);

        $pricingResponse->assertStatus(200);
        $finalAmount = $pricingResponse->json('data.aggregated.final_total');
        $subtotal = $pricingResponse->json('data.aggregated.subtotal_before_discount');

        $bookingPayload = [
            'campaign_title' => 'Flash Sale 30 Min',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
            'screen_ids' => [$this->fixedScreen->id],
            'total_screens' => 1,
            'subtotal' => $subtotal,
            'final_amount' => $finalAmount,
            'advertisement_category_id' => $this->category->id,
        ];

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/bookings/campaign', $bookingPayload);

        $response->assertStatus(201);
        $bookingId = $response->json('data.id');
        $this->assertNotNull($bookingId);

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'campaign_title' => 'Flash Sale 30 Min',
            'user_id' => $this->customer->id,
        ]);

        $booking = Booking::find($bookingId);
        $this->assertEquals('2026-08-28 10:00:00', $booking->start_time->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-08-28 10:30:00', $booking->end_time->format('Y-m-d H:i:s'));
        $this->assertNotNull($booking->pricing_snapshot);
    }

    public function test_customer_booking_fails_when_below_screen_minimum_duration()
    {
        // 15-minute booking on a 30-minute minimum screen
        $bookingPayload = [
            'campaign_title' => 'Too Short Campaign',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:15:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:15',
            'screen_ids' => [$this->fixedScreen->id],
            'total_screens' => 1,
            'subtotal' => 150,
            'final_amount' => 150,
            'advertisement_category_id' => $this->category->id,
        ];

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/bookings/campaign', $bookingPayload);

        $response->assertStatus(422);
        $response->assertJsonPath('status', false);
    }

    public function test_customer_booking_fails_when_end_time_not_after_start_time()
    {
        $bookingPayload = [
            'campaign_title' => 'Zero Duration',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:00:00',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:00',
            'screen_ids' => [$this->fixedScreen->id],
            'total_screens' => 1,
            'subtotal' => 300,
            'final_amount' => 300,
            'advertisement_category_id' => $this->category->id,
        ];

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/bookings/campaign', $bookingPayload);

        $response->assertStatus(422);
    }

    public function test_customer_booking_rejects_conflicting_interval_on_fixed_screen()
    {
        // First booking: 10:00 to 10:30
        $booking = Booking::create([
            'user_id' => $this->customer->id,
            'title' => 'Existing Morning Booking',
            'campaign_title' => 'Existing Morning Booking',
            'advertiser_name' => 'John Doe',
            'advertiser_email' => 'john@example.com',
            'status' => Booking::STATUS_APPROVED,
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'subtotal' => 300,
            'final_amount' => 300,
            'advertisement_category_id' => $this->category->id,
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreen->id,
            'quantity' => 1,
            'price_per_unit' => 300,
            'total_price' => 300,
        ]);

        // Attempt second booking in overlapping window: 10:15 to 10:45
        $bookingPayload = [
            'campaign_title' => 'Overlapping Booking',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:15:00',
            'end_time' => '2026-08-28 10:45:00',
            'daily_start_time' => '10:15',
            'daily_end_time' => '10:45',
            'screen_ids' => [$this->fixedScreen->id],
            'total_screens' => 1,
            'subtotal' => 300,
            'final_amount' => 300,
            'advertisement_category_id' => $this->category->id,
        ];

        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/bookings/campaign', $bookingPayload);

        $response->assertStatus(422);
        $response->assertJsonPath('status', false);
    }

    public function test_check_conflicts_endpoint_identifies_fixed_screen_timestamp_overlap()
    {
        // Create existing booking 10:00 to 10:30
        $booking = Booking::create([
            'user_id' => $this->customer->id,
            'title' => 'Existing Booking',
            'campaign_title' => 'Existing Booking',
            'advertiser_name' => 'Jane Doe',
            'advertiser_email' => 'jane@example.com',
            'status' => Booking::STATUS_APPROVED,
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-08-28 10:00:00',
            'end_time' => '2026-08-28 10:30:00',
            'subtotal' => 300,
            'final_amount' => 300,
            'advertisement_category_id' => $this->category->id,
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $this->fixedScreen->id,
            'quantity' => 1,
            'price_per_unit' => 300,
            'total_price' => 300,
        ]);

        // Overlapping query: 10:10 to 10:40
        $overlapResponse = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/bookings/check-conflicts', [
                'screen_id' => $this->fixedScreen->id,
                'start_time' => '2026-08-28 10:10:00',
                'end_time' => '2026-08-28 10:40:00',
            ]);

        $overlapResponse->assertStatus(200);
        $overlapResponse->assertJsonPath('data.has_available_capacity', false);

        // Non-overlapping query: 10:30 to 11:00 (adjacent)
        $cleanResponse = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/bookings/check-conflicts', [
                'screen_id' => $this->fixedScreen->id,
                'start_time' => '2026-08-28 10:30:00',
                'end_time' => '2026-08-28 11:00:00',
            ]);

        $cleanResponse->assertStatus(200);
        $cleanResponse->assertJsonPath('data.has_available_capacity', true);
    }
}
