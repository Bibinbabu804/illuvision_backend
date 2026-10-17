<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\State;
use App\Services\CampaignPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint PR-MIN6 — Budget Planner Intraday Recommendation Engine Tests.
 *
 * Tests Mode B (Intraday / Time-Slot Campaigns) covering:
 * - 30-minute campaign recommendation calculation
 * - Per-asset minimum duration filtering (30m vs 1h vs 1d plans)
 * - Fixed screen timestamp collision exclusion
 * - Mobile fleet capacity during overlapping intervals
 * - Intraday extended duration strategy (More Time in minutes)
 * - Pricing equality with CampaignPricingService
 * - Input validation (end_time > start_time)
 * - Budget adherence across all intraday packages
 */
class BudgetRecommendationIntradayTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;
    private State $kerala;
    private City $kochi;

    private ScreenCategory $mall;
    private ScreenCategory $theatre;

    private PricingPlan $minutePlan30; // ₹10/min, min 30 minutes
    private PricingPlan $minutePlan60; // ₹15/min, min 60 minutes
    private PricingPlan $dailyPlan;    // ₹1000/day, min 1 day

    private Screen $fixedScreen30m;
    private Screen $fixedScreen60m;
    private Screen $fixedScreenDaily;
    private Screen $mobileFleet;

    private \App\Models\User $user;
    private string $date = '2026-08-28';

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\RateLimiter::clear('recommendations');

        $this->user = \App\Models\User::create([
            'name' => 'Test User',
            'email' => 'testuser@example.com',
            'password' => bcrypt('password'),
        ]);

        // Geography
        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $this->kerala = State::create(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->kochi = City::create(['name' => 'Kochi', 'state_id' => $this->kerala->id, 'country_id' => $this->country->id]);

        // Categories
        $this->mall = ScreenCategory::create(['name' => 'Mall', 'slug' => 'mall', 'status' => 'active']);
        $this->theatre = ScreenCategory::create(['name' => 'Theatre', 'slug' => 'theatre', 'status' => 'active']);

        // Pricing plans
        $this->minutePlan30 = PricingPlan::create([
            'name' => '30 Min Plan',
            'base_price' => 10,
            'currency' => 'INR',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'Minute',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->minutePlan60 = PricingPlan::create([
            'name' => '1 Hour Plan',
            'base_price' => 15,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Hour',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->dailyPlan = PricingPlan::create([
            'name' => '1 Day Plan',
            'base_price' => 1000,
            'currency' => 'INR',
            'minimum_duration' => 1,
            'minimum_duration_type' => 'Day',
            'is_default' => true,
            'is_active' => true,
        ]);

        // Screens
        $this->fixedScreen30m = Screen::create([
            'name' => 'Kochi Lulu 30m Screen',
            'screen_code' => 'FIX-30M',
            'asset_type' => 'fixed',
            'screen_type' => 'LED Billboard',
            'screen_category_id' => $this->mall->id,
            'country_id' => $this->country->id,
            'city_id' => $this->kochi->id,
            'location_name' => 'Lulu Mall Kochi',
            'pricing_plan_id' => $this->minutePlan30->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->fixedScreen60m = Screen::create([
            'name' => 'Kochi Centre 60m Screen',
            'screen_code' => 'FIX-60M',
            'asset_type' => 'fixed',
            'screen_type' => 'LED Billboard',
            'screen_category_id' => $this->mall->id,
            'country_id' => $this->country->id,
            'city_id' => $this->kochi->id,
            'location_name' => 'Centre Square Kochi',
            'pricing_plan_id' => $this->minutePlan60->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->fixedScreenDaily = Screen::create([
            'name' => 'Kochi Daily Mall Screen',
            'screen_code' => 'FIX-DAY',
            'asset_type' => 'fixed',
            'screen_type' => 'LED Billboard',
            'screen_category_id' => $this->mall->id,
            'country_id' => $this->country->id,
            'city_id' => $this->kochi->id,
            'location_name' => 'Oberon Mall Kochi',
            'pricing_plan_id' => $this->dailyPlan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->mobileFleet = Screen::create([
            'name' => 'Kerala Mobile Van Fleet',
            'screen_code' => 'MOB-VAN',
            'asset_type' => 'mobile',
            'screen_type' => 'Digital Van',
            'screen_category_id' => $this->theatre->id,
            'country_id' => $this->country->id,
            'location_name' => 'Kochi Depot',
            'pricing_plan_id' => $this->minutePlan30->id,
            'fleet_size' => 100,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
        $this->mobileFleet->coverageStates()->attach([$this->kerala->id]);
        $this->mobileFleet->coverageCities()->attach([$this->kochi->id]);
    }

    private function callCalculate(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'budget' => 50000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'campaign_preference' => 'more_screens',
            'campaign_mode' => 'intraday',
            'start_date' => $this->date,
            'end_date' => $this->date,
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
        ], $overrides);

        return $this->postJson('/api/recommendations/calculate', $payload);
    }

    public function test_intraday_recommendation_30_minutes_basic(): void
    {
        $response = $this->callCalculate();

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $firstRec = $recommendations[0];
        $summary = $firstRec['campaign_summary'];

        $this->assertEquals('intraday', $summary['campaign_mode']);
        $this->assertEquals($this->date, $summary['start_date']);
        $this->assertEquals('10:00', $summary['daily_start_time']);
        $this->assertEquals('10:30', $summary['daily_end_time']);
        $this->assertEquals(30, $summary['duration_minutes']);
        $this->assertStringContainsString('30 Minute', $summary['duration_label']);
    }

    public function test_intraday_evaluates_plan_minimum_durations(): void
    {
        // 30-minute request:
        // - fixedScreen30m (min 30 min) -> ELIGIBLE
        // - fixedScreen60m (min 60 min) -> INELIGIBLE (excluded)
        // - fixedScreenDaily (min 1 day) -> INELIGIBLE (excluded)
        $response = $this->callCalculate([
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
        ]);

        $response->assertStatus(200);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $bestMatch = collect($recommendations)->firstWhere('strategy', 'preferred_duration') ?? $recommendations[0];
        $assetIds = array_column($bestMatch['assets'], 'asset_id');

        $this->assertContains($this->fixedScreen30m->id, $assetIds, '30m-min screen must be included.');
        $this->assertNotContains($this->fixedScreen60m->id, $assetIds, '60m-min screen must be excluded from 30m campaign.');
        $this->assertNotContains($this->fixedScreenDaily->id, $assetIds, '1day-min screen must be excluded from 30m campaign.');
    }

    public function test_intraday_fixed_screen_timestamp_collision_exclusion(): void
    {
        // Create an existing confirmed booking from 10:00 to 10:30 on fixedScreen30m
        $bookingId = DB::table('bookings')->insertGetId([
            'user_id' => $this->user->id,
            'title' => 'Existing Morning Slot',
            'advertiser_name' => 'Existing Advertiser',
            'start_time' => $this->date . ' 10:00:00',
            'end_time' => $this->date . ' 10:30:00',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('booking_screens')->insert([
            'booking_id' => $bookingId,
            'screen_id' => $this->fixedScreen30m->id,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Query overlapping interval: 10:15 to 10:45
        // fixedScreen30m is conflicted, other screens don't match 30m duration -> no inventory available
        $response = $this->callCalculate([
            'daily_start_time' => '10:15',
            'daily_end_time' => '10:45',
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEmpty($data['recommendations']);
        $this->assertEquals('NO_AVAILABILITY', $data['no_result']['reason']);

        // Query non-overlapping interval: 10:30 to 11:00
        // fixedScreen30m is available!
        $response2 = $this->callCalculate([
            'daily_start_time' => '10:30',
            'daily_end_time' => '11:00',
        ]);

        $response2->assertStatus(200);
        $this->assertNotEmpty($response2->json('data.recommendations'));
    }

    public function test_intraday_mobile_fleet_capacity_overlap(): void
    {
        // Reserve 60 of 100 units on mobileFleet from 10:00 to 11:00
        $bookingId = DB::table('bookings')->insertGetId([
            'user_id' => $this->user->id,
            'title' => 'Fleet reservation',
            'advertiser_name' => 'Fleet Advertiser',
            'start_time' => $this->date . ' 10:00:00',
            'end_time' => $this->date . ' 11:00:00',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('booking_screens')->insert([
            'booking_id' => $bookingId,
            'screen_id' => $this->mobileFleet->id,
            'quantity' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Request 10:30 to 11:00 on theatre category (mobile fleet)
        $response = $this->callCalculate([
            'screen_category_ids' => [$this->theatre->id],
            'daily_start_time' => '10:30',
            'daily_end_time' => '11:00',
            'budget' => 100000,
        ]);

        $response->assertStatus(200);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $rec) {
            foreach ($rec['assets'] as $asset) {
                if ($asset['asset_id'] === $this->mobileFleet->id) {
                    $this->assertLessThanOrEqual(40, $asset['quantity'], 'Mobile quantity must not exceed remaining 40 available units.');
                    $this->assertEquals(40, $asset['available_units']);
                }
            }
        }
    }

    public function test_intraday_more_time_strategy_extends_minutes_not_days(): void
    {
        // Budget ₹50,000 is plenty for a 30m screen at ₹10/min (base ₹300).
        // Strategy "More Time" should explore longer minute intervals (e.g. 45m or 60m).
        $response = $this->callCalculate([
            'budget' => 50000,
            'daily_start_time' => '10:00',
            'daily_end_time' => '10:30',
        ]);

        $response->assertStatus(200);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $moreTimePkg = collect($recommendations)->firstWhere('strategy', 'extended_duration');
        if ($moreTimePkg) {
            $summary = $moreTimePkg['campaign_summary'];
            $this->assertGreaterThan(30, $summary['duration_minutes'], 'More Time must extend duration in minutes.');
            $this->assertEquals($this->date, $summary['start_date']);
            $this->assertEquals($this->date, $summary['end_date']);
        }
    }

    public function test_intraday_pricing_equals_campaign_pricing_service_oracle(): void
    {
        $response = $this->callCalculate();

        $response->assertStatus(200);
        $rec = $response->json('data.recommendations')[0];

        $oracle = app(CampaignPricingService::class)->calculateCampaignPreview([
            'screen_ids' => array_column($rec['assets'], 'asset_id'),
            'quantities' => collect($rec['assets'])->mapWithKeys(fn ($a) => [$a['asset_id'] => $a['quantity']])->all(),
            'start_date' => $rec['campaign_summary']['start_date'],
            'end_date' => $rec['campaign_summary']['end_date'],
            'daily_start_time' => $rec['campaign_summary']['daily_start_time'],
            'daily_end_time' => $rec['campaign_summary']['daily_end_time'],
        ]);

        $this->assertEquals(
            (float) $oracle['aggregated']['final_amount'],
            (float) $rec['pricing']['final_amount'],
            'Engine final_amount must equal CampaignPricingService final_amount for intraday.'
        );
    }

    public function test_intraday_validation_rejects_end_time_before_start_time(): void
    {
        $response = $this->callCalculate([
            'daily_start_time' => '10:30',
            'daily_end_time' => '10:00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_time']);
    }

    public function test_intraday_budget_adherence(): void
    {
        $response = $this->callCalculate(['budget' => 350]);

        $response->assertStatus(200);
        foreach ($response->json('data.recommendations') as $rec) {
            $this->assertLessThanOrEqual(350, $rec['pricing']['final_amount']);
            $this->assertTrue($rec['ranking_metrics']['budget_adherence']);
        }
    }
}
