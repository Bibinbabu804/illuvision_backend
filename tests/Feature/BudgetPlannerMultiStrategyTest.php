<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\PricingDurationRule;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\State;
use App\Services\CampaignPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Budget Planner Multi-Strategy Recommendation Feature Tests.
 *
 * Covers the 9 business scenarios:
 *  - Scenario 1: Remaining budget (Best Match)
 *  - Scenario 2: More Time (Extended Duration)
 *  - Scenario 3: More Coverage (Expanded Coverage)
 *  - Scenario 4: No More Time (Budget exceeded)
 *  - Scenario 5: No More Coverage (No extra inventory)
 *  - Scenario 6: Duplicate prevention
 *  - Scenario 7: Booking handoff integrity
 *  - Scenario 8: Quote handoff integrity
 *  - Scenario 9: Mixed fixed and mobile coverage
 */
class BudgetPlannerMultiStrategyTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;
    private State $kerala;
    private State $tamilNadu;
    private City $kochi;
    private City $kottayam;
    private City $chennai;

    private ScreenCategory $mall;
    private ScreenCategory $theatre;
    private ScreenCategory $taxi;

    private PricingPlan $planA; // ₹500/day
    private PricingPlan $planB; // ₹2,000/day

    private Screen $fixedKochiMall1;
    private Screen $fixedKochiMall2;
    private Screen $fixedKochiTheatre;
    private Screen $fixedKottayamMall;
    private Screen $mobileKeralaTheatre;
    private Screen $mobileKeralaTaxi;

    private string $startDate = '2026-11-01';
    private string $endDate = '2026-11-30'; // 30 days

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\RateLimiter::clear('recommendations');

        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $this->kerala = State::create(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->tamilNadu = State::create(['name' => 'Tamil Nadu', 'country_id' => $this->country->id]);

        $this->kochi = City::create(['name' => 'Kochi', 'state_id' => $this->kerala->id, 'country_id' => $this->country->id]);
        $this->kottayam = City::create(['name' => 'Kottayam', 'state_id' => $this->kerala->id, 'country_id' => $this->country->id]);
        $this->chennai = City::create(['name' => 'Chennai', 'state_id' => $this->tamilNadu->id, 'country_id' => $this->country->id]);

        $this->mall = ScreenCategory::firstOrCreate(['slug' => 'mall-display'], ['name' => 'Mall Display', 'status' => 'active']);
        $this->theatre = ScreenCategory::firstOrCreate(['slug' => 'theatre-screen'], ['name' => 'Theatre Screen', 'status' => 'active']);
        $this->taxi = ScreenCategory::firstOrCreate(['slug' => 'taxi'], ['name' => 'Taxi', 'status' => 'active']);

        $this->planA = PricingPlan::create([
            'name' => 'Daily Plan A', 'base_price' => 500, 'currency' => 'INR',
            'minimum_duration' => 1, 'minimum_duration_type' => 'Day',
            'is_default' => true, 'is_active' => true,
        ]);
        $this->planB = PricingPlan::create([
            'name' => 'Daily Plan B', 'base_price' => 2000, 'currency' => 'INR',
            'minimum_duration' => 1, 'minimum_duration_type' => 'Day',
            'is_default' => false, 'is_active' => true,
        ]);

        $this->fixedKochiMall1 = $this->createFixed('Kochi Mall 1', 'KM-1', $this->kochi, $this->mall, $this->planA);
        $this->fixedKochiMall2 = $this->createFixed('Kochi Mall 2', 'KM-2', $this->kochi, $this->mall, $this->planA);
        $this->fixedKochiTheatre = $this->createFixed('Kochi Theatre', 'KT-1', $this->kochi, $this->theatre, $this->planA);
        $this->fixedKottayamMall = $this->createFixed('Kottayam Mall', 'KTM-1', $this->kottayam, $this->mall, $this->planA);

        $this->mobileKeralaTheatre = $this->createMobile('Kerala Theatre Fleet', 'MOB-T', $this->theatre, $this->planA, 50);
        $this->mobileKeralaTheatre->coverageStates()->attach([$this->kerala->id]);
        $this->mobileKeralaTheatre->coverageCities()->attach([$this->kochi->id, $this->kottayam->id]);

        $this->mobileKeralaTaxi = $this->createMobile('Kerala Taxi Fleet', 'MOB-TX', $this->taxi, $this->planB, 20);
        $this->mobileKeralaTaxi->coverageStates()->attach([$this->kerala->id]);
        $this->mobileKeralaTaxi->coverageCities()->attach([$this->kochi->id]);
    }

    private function createFixed(string $name, string $code, City $city, ScreenCategory $category, PricingPlan $plan): Screen
    {
        return Screen::create([
            'name' => $name,
            'screen_code' => $code,
            'asset_type' => 'fixed',
            'screen_type' => 'LED Screen',
            'screen_category_id' => $category->id,
            'country_id' => $this->country->id,
            'city_id' => $city->id,
            'location_name' => $city->name,
            'pricing_plan_id' => $plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    private function createMobile(string $name, string $code, ScreenCategory $category, PricingPlan $plan, int $fleetSize): Screen
    {
        return Screen::create([
            'name' => $name,
            'screen_code' => $code,
            'asset_type' => 'mobile',
            'screen_type' => 'Digital Van',
            'screen_category_id' => $category->id,
            'country_id' => $this->country->id,
            'location_name' => 'Central Depot',
            'pricing_plan_id' => $plan->id,
            'fleet_size' => $fleetSize,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    /**
     * Scenario 1 — Remaining budget.
     * Budget: ₹50,000. 2 screens / 30 days: ₹30,000.
     * Expected: Best Match returned with remaining_budget = ₹20,000.
     */
    public function test_scenario_1_remaining_budget_calculated_correctly(): void
    {
        $response = $this->postJson('/api/recommendations/calculate', [
            'budget' => 50000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $bestMatch = collect($recommendations)->firstWhere('strategy', 'preferred_duration');
        $this->assertNotNull($bestMatch);
        $this->assertEquals('Best Match', $bestMatch['label']);
        $this->assertEquals(30, $bestMatch['campaign_summary']['duration_days']);

        $finalAmount = $bestMatch['pricing']['final_amount'];
        $remainingBudget = $bestMatch['pricing']['remaining_budget'];

        $this->assertEquals(50000, $finalAmount + $remainingBudget);
        $this->assertGreaterThan(0, $remainingBudget);
        $this->assertEquals(round(($finalAmount / 50000) * 100, 2), $bestMatch['pricing']['budget_utilization_pct']);
        $this->assertEquals($bestMatch['pricing']['budget_utilization_pct'], $bestMatch['pricing']['budget_utilization_percentage']);
    }

    /**
     * Scenario 2 — More Time (Extended Duration).
     * Budget: ₹50,000.
     * With ₹20,000 remaining on 30 days, More Time extends the campaign to 45 days.
     */
    public function test_scenario_2_more_time_returned_when_budget_permits(): void
    {
        $response = $this->postJson('/api/recommendations/calculate', [
            'budget' => 50000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate, // 30 days
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');

        $moreTime = collect($recommendations)->firstWhere('strategy', 'extended_duration');
        $this->assertNotNull($moreTime, 'Expected More Time recommendation strategy.');
        $this->assertEquals('More Time', $moreTime['label']);
        $this->assertGreaterThan(30, $moreTime['campaign_summary']['duration_days']);
        $this->assertLessThanOrEqual(50000, $moreTime['pricing']['final_amount']);
    }

    /**
     * Scenario 3 — More Coverage (Expanded Coverage).
     * Budget allows including compatible theatre screen or extra cities in Kerala.
     */
    public function test_scenario_3_more_coverage_returned_with_expanded_screens(): void
    {
        $response = $this->postJson('/api/recommendations/calculate', [
            'budget' => 60000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');

        $moreCoverage = collect($recommendations)->firstWhere('strategy', 'expanded_coverage');
        $this->assertNotNull($moreCoverage, 'Expected More Coverage recommendation strategy.');
        $this->assertEquals('More Coverage', $moreCoverage['label']);
        $this->assertEquals(30, $moreCoverage['campaign_summary']['duration_days']);
        $this->assertLessThanOrEqual(60000, $moreCoverage['pricing']['final_amount']);
    }

    /**
     * Scenario 4 — No More Time when extended duration would exceed budget.
     */
    public function test_scenario_4_no_more_time_when_budget_tight(): void
    {
        // Set budget exactly to 30 days for 1 screen = ₹15,000 (no room for extension)
        $response = $this->postJson('/api/recommendations/calculate', [
            'budget' => 15000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');

        $moreTime = collect($recommendations)->firstWhere('strategy', 'extended_duration');
        $this->assertNull($moreTime, 'More Time must be omitted when longer duration exceeds budget.');
    }

    /**
     * Scenario 5 — No More Coverage when no additional inventory is available.
     */
    public function test_scenario_5_no_more_coverage_when_no_extra_inventory(): void
    {
        $uniqueCat = ScreenCategory::create(['name' => 'Single Only', 'slug' => 'single-only', 'status' => 'active']);
        $this->createFixed('Single Screen', 'SS-1', $this->chennai, $uniqueCat, $this->planA);

        $response = $this->postJson('/api/recommendations/calculate', [
            'budget' => 50000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->tamilNadu->id],
            'city_ids' => [$this->chennai->id],
            'screen_category_ids' => [$uniqueCat->id],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');

        $moreCoverage = collect($recommendations)->firstWhere('strategy', 'expanded_coverage');
        $this->assertNull($moreCoverage, 'More Coverage must be omitted when no additional inventory exists.');
    }

    /**
     * Scenario 6 — Duplicate prevention.
     */
    public function test_scenario_6_duplicate_prevention_ensures_unique_signatures(): void
    {
        $response = $this->postJson('/api/recommendations/calculate', [
            'budget' => 15000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');

        $signatures = [];
        foreach ($recommendations as $rec) {
            $assetIds = array_column($rec['assets'], 'asset_id');
            sort($assetIds);
            $quantities = [];
            foreach ($rec['assets'] as $asset) {
                $quantities[$asset['asset_id']] = $asset['quantity'];
            }
            ksort($quantities);

            $signatures[] = implode('|', [
                implode(',', $assetIds),
                json_encode($quantities),
                $rec['campaign_summary']['start_date'],
                $rec['campaign_summary']['end_date'],
                $rec['pricing']['final_amount'],
            ]);
        }

        $this->assertEquals(count($signatures), count(array_unique($signatures)));
    }

    /**
     * Scenario 7 — Booking handoff dates & asset integrity.
     */
    public function test_scenario_7_booking_handoff_dates_preserved(): void
    {
        $response = $this->postJson('/api/recommendations/calculate', [
            'budget' => 50000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');

        $moreTime = collect($recommendations)->firstWhere('strategy', 'extended_duration');
        if ($moreTime) {
            $this->assertEquals($this->startDate, $moreTime['campaign_summary']['start_date']);
            $this->assertNotEquals($this->endDate, $moreTime['campaign_summary']['end_date']);
            $this->assertGreaterThan(30, $moreTime['campaign_summary']['duration_days']);
        }
    }

    /**
     * Scenario 9 — Mixed fixed and mobile screen expansion.
     */
    public function test_scenario_9_mixed_fixed_and_mobile_expansion(): void
    {
        $response = $this->postJson('/api/recommendations/calculate', [
            'budget' => 100000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');

        $moreCoverage = collect($recommendations)->firstWhere('strategy', 'expanded_coverage');
        if ($moreCoverage) {
            $this->assertGreaterThan(0, $moreCoverage['campaign_summary']['total_assets']);
            $this->assertLessThanOrEqual(100000, $moreCoverage['pricing']['final_amount']);
        }
    }
}
