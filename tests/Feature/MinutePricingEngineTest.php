<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use App\Models\PricingScreenRule;
use App\Models\PricingDurationRule;
use App\Models\PricingPeakRule;
use App\Models\PricingWeekendRule;
use App\Models\Screen;
use App\Models\Country;
use App\Models\City;
use App\Models\User;
use App\Services\PricingRuleEngine;
use App\Services\CampaignPricingService;
use App\Services\Pricing\DailyPricingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MinutePricingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected PricingRuleEngine $engine;
    protected CampaignPricingService $campaignPricing;
    protected DailyPricingCalculator $dailyCalculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(PricingRuleEngine::class);
        $this->campaignPricing = app(CampaignPricingService::class);
        $this->dailyCalculator = app(DailyPricingCalculator::class);
    }

    /**
     * Test canonical normalization for all supported units.
     */
    public function test_canonical_minute_normalization()
    {
        // 1. Minute suite
        $this->assertEquals(1.0, $this->engine->durationToMinutes(1, 'Minute'));
        $this->assertEquals(5.0, $this->engine->durationToMinutes(5, 'Minute'));
        $this->assertEquals(10.0, $this->engine->durationToMinutes(10, 'Minute'));
        $this->assertEquals(15.0, $this->engine->durationToMinutes(15, 'Minute'));
        $this->assertEquals(30.0, $this->engine->durationToMinutes(30, 'Minute'));
        $this->assertEquals(45.0, $this->engine->durationToMinutes(45, 'Minute'));
        $this->assertEquals(60.0, $this->engine->durationToMinutes(60, 'Minute'));
        $this->assertEquals(90.0, $this->engine->durationToMinutes(90, 'Minute'));
        $this->assertEquals(120.0, $this->engine->durationToMinutes(120, 'Minute'));

        // 2. Hour suite
        $this->assertEquals(60.0, $this->engine->durationToMinutes(1, 'Hour'));
        $this->assertEquals(120.0, $this->engine->durationToMinutes(2, 'Hour'));

        // 3. Day suite
        $this->assertEquals(1440.0, $this->engine->durationToMinutes(1, 'Day'));
        $this->assertEquals(2880.0, $this->engine->durationToMinutes(2, 'Day'));

        // 4. Week suite
        $this->assertEquals(10080.0, $this->engine->durationToMinutes(1, 'Week'));

        // 5. Month suite (30-day month semantics preserved)
        $this->assertEquals(43200.0, $this->engine->durationToMinutes(1, 'Month'));
        $this->assertEquals(129600.0, $this->engine->durationToMinutes(3, 'Month'));
        $this->assertEquals(259200.0, $this->engine->durationToMinutes(6, 'Month'));
        $this->assertEquals(518400.0, $this->engine->durationToMinutes(12, 'Month'));
    }

    /**
     * Test durationToHours and hoursToDuration conversion precision and symmetry.
     */
    public function test_duration_to_hours_and_hours_to_duration_conversions()
    {
        // Minute to Hours
        $this->assertEquals(0.5, $this->engine->durationToHours(30, 'Minute'));
        $this->assertEquals(0.25, $this->engine->durationToHours(15, 'Minute'));
        $this->assertEquals(0.75, $this->engine->durationToHours(45, 'Minute'));
        $this->assertEquals(1.0, $this->engine->durationToHours(60, 'Minute'));
        $this->assertEquals(2.0, $this->engine->durationToHours(120, 'Minute'));

        // Existing units to Hours
        $this->assertEquals(1.0, $this->engine->durationToHours(1, 'Hour'));
        $this->assertEquals(24.0, $this->engine->durationToHours(1, 'Day'));
        $this->assertEquals(168.0, $this->engine->durationToHours(1, 'Week'));
        $this->assertEquals(720.0, $this->engine->durationToHours(1, 'Month'));

        // Hours to Duration symmetric conversion
        $this->assertEquals(30.0, $this->engine->hoursToDuration(0.5, 'Minute'));
        $this->assertEquals(15.0, $this->engine->hoursToDuration(0.25, 'Minute'));
        $this->assertEquals(45.0, $this->engine->hoursToDuration(0.75, 'Minute'));
        $this->assertEquals(60.0, $this->engine->hoursToDuration(1.0, 'Minute'));
        $this->assertEquals(1.0, $this->engine->hoursToDuration(1.0, 'Hour'));
        $this->assertEquals(1.0, $this->engine->hoursToDuration(24.0, 'Day'));
        $this->assertEquals(1.0, $this->engine->hoursToDuration(720.0, 'Month'));
    }

    /**
     * Test that unsupported duration units throw an InvalidArgumentException.
     */
    public function test_unsupported_duration_unit_throws_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->durationToMinutes(10, 'Centuries');
    }

    /**
     * Test minimum duration validation matrix across minute, hour, and day plans.
     */
    public function test_minimum_duration_validation_matrix()
    {
        $country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India', 'is_active' => true]);
        $city = City::create(['country_id' => $country->id, 'name' => 'Kochi', 'is_active' => true]);

        // Plan A: 30-minute minimum
        $plan30m = PricingPlan::create([
            'name' => 'Quick 30m Plan',
            'base_price' => 500.00,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Minute',
            'is_active' => true,
        ]);
        $screen30m = Screen::create([
            'name' => 'Screen 30m',
            'screen_code' => 'SC-30M',
            'screen_type' => 'Billboard',
            'country_id' => $country->id,
            'city_id' => $city->id,
            'location_name' => 'Kochi City Center',
            'pricing_plan_id' => $plan30m->id,
            'status' => 'active',
        ]);

        // Plan B: 1-hour minimum
        $plan1h = PricingPlan::create([
            'name' => 'Standard 1h Plan',
            'base_price' => 1000.00,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Hour',
            'is_active' => true,
        ]);
        $screen1h = Screen::create([
            'name' => 'Screen 1h',
            'screen_code' => 'SC-1H',
            'screen_type' => 'Billboard',
            'country_id' => $country->id,
            'city_id' => $city->id,
            'location_name' => 'Kochi Bypass',
            'pricing_plan_id' => $plan1h->id,
            'status' => 'active',
        ]);

        // Plan C: 1-day minimum
        $plan1d = PricingPlan::create([
            'name' => 'Daily 1d Plan',
            'base_price' => 5000.00,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Day',
            'is_active' => true,
        ]);
        $screen1d = Screen::create([
            'name' => 'Screen 1d',
            'screen_code' => 'SC-1D',
            'screen_type' => 'Billboard',
            'country_id' => $country->id,
            'city_id' => $city->id,
            'location_name' => 'Kochi Metro',
            'pricing_plan_id' => $plan1d->id,
            'status' => 'active',
        ]);

        // Test Plan A (30m min) against various requested durations
        // 1m -> reject
        $errors = $this->campaignPricing->validateMinimumDuration([$screen30m->id], '2026-08-28', '2026-08-28', '10:00', '10:01');
        $this->assertNotEmpty($errors);

        // 5m -> reject
        $errors = $this->campaignPricing->validateMinimumDuration([$screen30m->id], '2026-08-28', '2026-08-28', '10:00', '10:05');
        $this->assertNotEmpty($errors);

        // 10m -> reject
        $errors = $this->campaignPricing->validateMinimumDuration([$screen30m->id], '2026-08-28', '2026-08-28', '10:00', '10:10');
        $this->assertNotEmpty($errors);

        // 15m -> reject
        $errors = $this->campaignPricing->validateMinimumDuration([$screen30m->id], '2026-08-28', '2026-08-28', '10:00', '10:15');
        $this->assertNotEmpty($errors);

        // 29m -> reject
        $errors = $this->campaignPricing->validateMinimumDuration([$screen30m->id], '2026-08-28', '2026-08-28', '10:00', '10:29');
        $this->assertNotEmpty($errors);

        // 30m -> allow
        $errors = $this->campaignPricing->validateMinimumDuration([$screen30m->id], '2026-08-28', '2026-08-28', '10:00', '10:30');
        $this->assertEmpty($errors);

        // 31m -> allow
        $errors = $this->campaignPricing->validateMinimumDuration([$screen30m->id], '2026-08-28', '2026-08-28', '10:00', '10:31');
        $this->assertEmpty($errors);

        // 45m -> allow
        $errors = $this->campaignPricing->validateMinimumDuration([$screen30m->id], '2026-08-28', '2026-08-28', '10:00', '10:45');
        $this->assertEmpty($errors);

        // 60m -> allow
        $errors = $this->campaignPricing->validateMinimumDuration([$screen30m->id], '2026-08-28', '2026-08-28', '10:00', '11:00');
        $this->assertEmpty($errors);

        // Test Plan B (1h min)
        // 30m -> reject
        $errors = $this->campaignPricing->validateMinimumDuration([$screen1h->id], '2026-08-28', '2026-08-28', '10:00', '10:30');
        $this->assertNotEmpty($errors);

        // 60m -> allow
        $errors = $this->campaignPricing->validateMinimumDuration([$screen1h->id], '2026-08-28', '2026-08-28', '10:00', '11:00');
        $this->assertEmpty($errors);

        // 90m -> allow
        $errors = $this->campaignPricing->validateMinimumDuration([$screen1h->id], '2026-08-28', '2026-08-28', '10:00', '11:30');
        $this->assertEmpty($errors);

        // Test Plan C (1d min)
        // 23h -> reject
        $errors = $this->campaignPricing->validateMinimumDuration([$screen1d->id], '2026-08-28', '2026-08-28', '00:00', '23:00');
        $this->assertNotEmpty($errors);

        // 24h -> allow (full day)
        $errors = $this->campaignPricing->validateMinimumDuration([$screen1d->id], '2026-08-28', '2026-08-28', '00:00', '23:59');
        $this->assertEmpty($errors);
    }

    /**
     * Test minute-based pricing plan calculations and duration discount rules.
     */
    public function test_minute_pricing_plan_calculation_with_duration_discounts()
    {
        $plan = PricingPlan::create([
            'name' => 'Minute Promo Plan',
            'base_price' => 10.00, // ₹10 per minute
            'currency' => 'INR',
            'minimum_duration' => 15,
            'minimum_duration_type' => 'Minute',
            'is_active' => true,
        ]);

        // Duration discount: >= 60 minutes gets 10% off
        PricingDurationRule::create([
            'pricing_plan_id' => $plan->id,
            'minimum_duration' => 60.00,
            'maximum_duration' => null,
            'duration_unit' => 'Minute',
            'discount_type' => 'Percentage',
            'discount_value' => 10.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        // 1. 30 Minutes Campaign on 1 screen
        // Base Price = 10 * 30 = 300
        // Discount = 0%
        // Final Price = 300
        $res30 = $this->engine->calculate($plan, [
            'screens' => 1,
            'start_date' => '2026-08-28',
            'end_date' => '2026-08-28',
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
        ]);
        $this->assertEquals(300.00, $res30['base_price']);
        $this->assertEquals(0.00, $res30['combined_discount']);
        $this->assertEquals(300.00, $res30['final_price']);

        // 2. 60 Minutes Campaign on 1 screen
        // Base Price = 10 * 60 = 600
        // Discount = 10% (60 INR)
        // Final Price = 540
        $res60 = $this->engine->calculate($plan, [
            'screens' => 1,
            'start_date' => '2026-08-28',
            'end_date' => '2026-08-28',
            'daily_start_time' => '10:00',
            'daily_end_time' => '11:00',
        ]);
        $this->assertEquals(600.00, $res60['base_price']);
        $this->assertEquals(10.00, $res60['combined_discount']);
        $this->assertEquals(60.00, $res60['discount_amount']);
        $this->assertEquals(540.00, $res60['final_price']);
    }

    /**
     * Test peak hour surcharge with partial minute overlap.
     */
    public function test_minute_campaign_with_peak_hour_markup()
    {
        $plan = PricingPlan::create([
            'name' => 'Hourly Plan With Peak',
            'base_price' => 1000.00, // ₹1,000 per hour
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Hour',
            'is_active' => true,
        ]);

        // Peak rule: 18:00 to 20:00, 1.5x multiplier on all days
        PricingPeakRule::create([
            'pricing_plan_id' => $plan->id,
            'day_of_week' => 'All',
            'start_time' => '18:00',
            'end_time' => '20:00',
            'pricing_type' => 'multiplier',
            'value' => 1.50,
            'status' => 'active',
        ]);

        // Campaign: 18:15 to 18:45 (30 minutes) on Friday
        // Base price = 1000 * 0.5h = 500
        // Peak overlap = 30 minutes (0.5 hours)
        // Peak surcharge = 1000 * 0.5 * (1.5 - 1.0) = 250
        // Final price = 500 + 250 = 750
        $result = $this->engine->calculate($plan, [
            'screens' => 1,
            'start_date' => '2026-08-28',
            'end_date' => '2026-08-28',
            'daily_start_time' => '18:15',
            'daily_end_time' => '18:45',
        ]);

        $this->assertEquals(500.00, $result['base_price']);
        $this->assertEquals(250.00, $result['peak_amount']);
        $this->assertEquals(750.00, $result['final_price']);
    }

    /**
     * Test CRUD API for creating a minute-based pricing plan.
     */
    public function test_pricing_plan_crud_api_with_minute_unit()
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@illuvision.net',
            'password' => bcrypt('password'),
            'role' => 'super-admin',
            'status' => 'active',
        ]);
        $this->actingAs($superAdmin);

        $response = $this->postJson('/api/pricing-plans', [
            'name' => 'Flash Promo 15m',
            'description' => '15 minute micro promotion plan',
            'base_price' => 250.00,
            'currency' => 'INR',
            'minimum_duration' => 15,
            'minimum_duration_type' => 'Minute',
            'duration_rules' => [
                [
                    'minimum_duration' => 30,
                    'maximum_duration' => 60,
                    'duration_unit' => 'Minute',
                    'discount_type' => 'Percentage',
                    'discount_value' => 5.00,
                    'sort_order' => 1,
                    'status' => 'active',
                ]
            ]
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.minDuration', 15);
        $response->assertJsonPath('data.minDurationType', 'Minute');
        $response->assertJsonPath('data.durationRules.0.durationUnit', 'Minute');
    }

    /**
     * Test live simulator preview API with minute duration.
     */
    public function test_pricing_plan_preview_endpoint_with_minute_unit()
    {
        $plan = PricingPlan::create([
            'name' => 'Simulator Test Plan',
            'base_price' => 1000.00,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Hour',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/pricing-plans/preview', [
            'pricing_plan_id' => $plan->id,
            'duration_hours' => 30.0,
            'duration_unit' => 'Minute',
            'screens' => 1,
            'day' => 'Mon',
            'time' => '10:00',
        ]);

        $response->assertStatus(200);
        // 30 Minutes on ₹1,000/hr plan -> ₹500
        $this->assertEquals(500.00, $response->json('data.base_price'));
        $this->assertEquals(500.00, $response->json('data.final_price'));
    }
}
