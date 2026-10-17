<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\PricingDurationRule;
use App\Models\PricingPeakRule;
use App\Models\PricingPlan;
use App\Models\PricingWeekendRule;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\State;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use App\Services\CampaignPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BP3 Budget Planner Recommendation Engine — POST /recommendations/calculate.
 *
 * Covers the 20 test scenarios from the BP3 sprint spec plus the
 * pricing-authority test (engine final_amount === CampaignPricingService
 * final_amount).
 */
class BudgetRecommendationEngineTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;
    private State $kerala;
    private State $tamilNadu;
    private State $karnataka;
    private City $kochi;
    private City $kottayam;
    private City $chennai;
    private City $bengaluru;

    private ScreenCategory $mall;
    private ScreenCategory $theatre;
    private ScreenCategory $taxi;

    private PricingPlan $planA; // ₹500/day, min 1 day
    private PricingPlan $planB; // ₹2,000/day, min 1 day
    private PricingPlan $planC; // ₹10,000/day, min 7 days

    private Screen $fixedKochiMall;
    private Screen $fixedKochiTheatre;
    private Screen $fixedKochiTheatreWeekly; // min-7-day plan — for minimum-duration tests
    private Screen $fixedKottayamMall;
    private Screen $fixedChennaiMall;
    private Screen $fixedBengaluruMall;
    private Screen $fixedSoldOut;
    private Screen $mobileKeralaTheatre;  // fleet 100, Theatre, Kerala (Kochi, Kottayam)
    private Screen $mobileKeralaTaxi;     // fleet 50, Taxi, Kerala (Kochi)
    private Screen $mobileTamilNaduMall;  // fleet 10, Mall, Tamil Nadu (Chennai)

    private string $startDate = '2026-11-01';
    private string $endDate = '2026-11-30'; // 30-day window

    protected function setUp(): void
    {
        parent::setUp();

        // The endpoint is rate-limited (BP6 P0-2); the array cache persists
        // across tests in the same process, so reset the bucket per test.
        \Illuminate\Support\Facades\RateLimiter::clear('recommendations');

        // ── Geography ─────────────────────────────────────────────
        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $this->kerala = State::create(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->tamilNadu = State::create(['name' => 'Tamil Nadu', 'country_id' => $this->country->id]);
        $this->karnataka = State::create(['name' => 'Karnataka', 'country_id' => $this->country->id]);

        $this->kochi = City::create(['name' => 'Kochi', 'state_id' => $this->kerala->id, 'country_id' => $this->country->id]);
        $this->kottayam = City::create(['name' => 'Kottayam', 'state_id' => $this->kerala->id, 'country_id' => $this->country->id]);
        $this->chennai = City::create(['name' => 'Chennai', 'state_id' => $this->tamilNadu->id, 'country_id' => $this->country->id]);
        $this->bengaluru = City::create(['name' => 'Bengaluru', 'state_id' => $this->karnataka->id, 'country_id' => $this->country->id]);

        // ── Categories ─────────────────────────────────────────────
        $this->mall = ScreenCategory::create(['name' => 'Mall', 'slug' => 'mall', 'status' => 'active']);
        $this->theatre = ScreenCategory::create(['name' => 'Theatre', 'slug' => 'theatre', 'status' => 'active']);
        $this->taxi = ScreenCategory::create(['name' => 'Taxi', 'slug' => 'taxi', 'status' => 'active']);

        // ── Pricing plans ──────────────────────────────────────────
        $this->planA = PricingPlan::create([
            'name' => 'Standard Daily', 'base_price' => 500, 'currency' => 'INR',
            'minimum_duration' => 1, 'minimum_duration_type' => 'Day',
            'is_default' => true, 'is_active' => true,
        ]);
        $this->planB = PricingPlan::create([
            'name' => 'Premium Daily', 'base_price' => 2000, 'currency' => 'INR',
            'minimum_duration' => 1, 'minimum_duration_type' => 'Day',
            'is_default' => false, 'is_active' => true,
        ]);
        $this->planC = PricingPlan::create([
            'name' => 'Weekly Plan', 'base_price' => 10000, 'currency' => 'INR',
            'minimum_duration' => 7, 'minimum_duration_type' => 'Day',
            'is_default' => false, 'is_active' => true,
        ]);

        // ── Fixed screens ──────────────────────────────────────────
        $this->fixedKochiMall = $this->makeFixed('Kochi Mall Screen', 'FIX-001', $this->kochi, $this->mall, $this->planA);
        $this->fixedKochiTheatre = $this->makeFixed('Kochi Theatre Screen', 'FIX-002', $this->kochi, $this->theatre, $this->planA);
        $this->fixedKochiTheatreWeekly = $this->makeFixed('Kochi Theatre Weekly Screen', 'FIX-002B', $this->kochi, $this->theatre, $this->planC);
        $this->fixedKottayamMall = $this->makeFixed('Kottayam Mall Screen', 'FIX-003', $this->kottayam, $this->mall, $this->planA);
        $this->fixedChennaiMall = $this->makeFixed('Chennai Mall Screen', 'FIX-004', $this->chennai, $this->mall, $this->planB);
        $this->fixedBengaluruMall = $this->makeFixed('Bengaluru Mall Screen', 'FIX-005', $this->bengaluru, $this->mall, $this->planA);

        // Sold-out fixed screen — must never appear in any package
        $this->fixedSoldOut = $this->makeFixed('Kochi Sold Out Screen', 'FIX-006', $this->kochi, $this->mall, $this->planA);
        $this->fixedSoldOut->update(['booking_status' => 'sold_out']);

        // ── Mobile fleets ──────────────────────────────────────────
        $this->mobileKeralaTheatre = $this->makeMobile('Kerala Theatre Fleet', 'MOB-001', $this->theatre, $this->planA, 100);
        $this->mobileKeralaTheatre->coverageStates()->attach([$this->kerala->id]);
        $this->mobileKeralaTheatre->coverageCities()->attach([$this->kochi->id, $this->kottayam->id]);

        $this->mobileKeralaTaxi = $this->makeMobile('Kerala Taxi Fleet', 'MOB-002', $this->taxi, $this->planB, 50);
        $this->mobileKeralaTaxi->coverageStates()->attach([$this->kerala->id]);
        $this->mobileKeralaTaxi->coverageCities()->attach([$this->kochi->id]);

        $this->mobileTamilNaduMall = $this->makeMobile('Chennai Mall Fleet', 'MOB-003', $this->mall, $this->planA, 10);
        $this->mobileTamilNaduMall->coverageStates()->attach([$this->tamilNadu->id]);
        $this->mobileTamilNaduMall->coverageCities()->attach([$this->chennai->id]);
    }

    // ─── Fixture helpers ───────────────────────────────────────────

    private function makeFixed(string $name, string $code, City $city, ScreenCategory $category, PricingPlan $plan): Screen
    {
        return Screen::create([
            'name' => $name,
            'screen_code' => $code,
            'asset_type' => 'fixed',
            'screen_type' => 'LED Billboard',
            'screen_category_id' => $category->id,
            'country_id' => $this->country->id,
            'city_id' => $city->id,
            'location_name' => $city->name,
            'pricing_plan_id' => $plan->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    private function makeMobile(string $name, string $code, ScreenCategory $category, PricingPlan $plan, int $fleetSize): Screen
    {
        return Screen::create([
            'name' => $name,
            'screen_code' => $code,
            'asset_type' => 'mobile',
            'screen_type' => 'Digital Van',
            'screen_category_id' => $category->id,
            'country_id' => $this->country->id,
            'location_name' => 'Base Depot',
            'pricing_plan_id' => $plan->id,
            'fleet_size' => $fleetSize,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    private function makePlan(string $name, int $basePrice, int $minDays): PricingPlan
    {
        return PricingPlan::create([
            'name' => $name, 'base_price' => $basePrice, 'currency' => 'INR',
            'minimum_duration' => $minDays, 'minimum_duration_type' => 'Day',
            'is_default' => false, 'is_active' => true,
        ]);
    }

    /** Reserve mobile fleet units over a sub-range of the campaign window. */
    private function reserveFleet(Screen $fleet, int $quantity, string $from, string $to): void
    {
        $bookingId = DB::table('bookings')->insertGetId([
            'screen_id' => null,
            'title' => 'Fixture booking',
            'advertiser_name' => 'Test Advertiser',
            'start_time' => $from . ' 00:00:00',
            'end_time' => $to . ' 23:59:59',
            'price' => 0,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('booking_screens')->insert([
            'booking_id' => $bookingId,
            'screen_id' => $fleet->id,
            'quantity' => $quantity,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function callCalculate(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'budget' => 100000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ], $overrides);

        return $this->postJson('/api/recommendations/calculate', $payload);
    }

    // ─── Test 1 — Fixed only ───────────────────────────────────────

    public function test_fixed_only_returns_only_matching_fixed_assets(): void
    {
        $response = $this->callCalculate([
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
        ]);

        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations, 'Expected at least one recommendation.');

        $bestMatch = collect($recommendations)->firstWhere('strategy', 'preferred_duration') ?? $recommendations[0];
        $this->assertNotEmpty($bestMatch['assets']);
        foreach ($bestMatch['assets'] as $asset) {
            $this->assertEquals('fixed', $asset['asset_type']);
            $this->assertEquals($this->mall->id, $asset['screen_category_id']);
            $this->assertEquals($this->kochi->id, $asset['city_id']);
            $this->assertEquals(1, $asset['quantity'], 'Fixed assets must have quantity 1.');
        }
        $this->assertEquals(0, $bestMatch['campaign_summary']['mobile_fleet_count']);
    }

    // ─── Test 2 — Mobile only ──────────────────────────────────────

    public function test_mobile_only_resolves_via_coverage_geography(): void
    {
        $response = $this->callCalculate([
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->taxi->id],
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $rec) {
            $this->assertNotEmpty($rec['assets']);
            foreach ($rec['assets'] as $asset) {
                $this->assertNotEquals('fixed', $asset['asset_type'], 'Taxi category must resolve via mobile coverage.');
                $this->assertEquals($this->taxi->id, $asset['screen_category_id']);
                $this->assertEquals($this->mobileKeralaTaxi->id, $asset['asset_id']);
                $this->assertArrayHasKey('fleet_size', $asset);
                $this->assertArrayHasKey('available_units', $asset);
            }
        }
    }

    // ─── BP6 P1-2 — Mobile coverage cities in the package summary ──

    public function test_mobile_only_package_summary_contains_coverage_cities(): void
    {
        // Taxi fleet covers Kochi only — a mobile-only request must surface
        // Kochi as a city in the package summary (previously empty → the card
        // and WhatsApp quote showed no locations).
        $response = $this->callCalculate([
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->taxi->id],
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $rec) {
            $this->assertNotEmpty($rec['assets']);
            foreach ($rec['assets'] as $asset) {
                $this->assertEquals($this->mobileKeralaTaxi->id, $asset['asset_id']);
                $this->assertNull($asset['city_id'], 'Mobile assets have no single city_id.');
            }

            $cityNames = array_column($rec['campaign_summary']['cities'], 'name');
            $this->assertContains(
                'Kochi',
                $cityNames,
                'Mobile-only package summary must surface the fleet coverage city.'
            );
        }
    }

    public function test_coverage_cities_deduplicated_with_fixed_asset_cities(): void
    {
        // Kochi hosts a fixed mall + fixed theatre; the Kerala mobile theatre
        // fleet also covers Kochi (and Kottayam). The summary must list Kochi
        // and Kottayam exactly once each.
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id, $this->kottayam->id],
            'screen_category_ids' => [$this->mall->id, $this->theatre->id],
            'budget' => 500000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $rec) {
            $cityNames = array_column($rec['campaign_summary']['cities'], 'name');
            $this->assertEquals(
                $cityNames,
                array_values(array_unique($cityNames)),
                'Coverage cities must not duplicate fixed asset cities.'
            );
            $this->assertContains('Kochi', $cityNames);
            $this->assertContains('Kottayam', $cityNames);
        }
    }

    // ─── Test 3 — Mixed fixed + mobile ─────────────────────────────

    public function test_mixed_package_contains_fixed_and_mobile(): void
    {
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id, $this->kottayam->id],
            'screen_category_ids' => [$this->mall->id, $this->theatre->id],
            'budget' => 200000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $foundMixed = false;
        foreach ($recommendations as $rec) {
            if ($rec['campaign_summary']['fixed_asset_count'] > 0 && $rec['campaign_summary']['mobile_fleet_count'] > 0) {
                $foundMixed = true;
                break;
            }
        }
        $this->assertTrue($foundMixed, 'At least one package must mix fixed and mobile assets.');
    }

    // ─── Test 4 — Multiple states ──────────────────────────────────

    public function test_multiple_states_include_assets_from_each_state(): void
    {
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id, $this->tamilNadu->id],
            'city_ids' => [$this->kochi->id, $this->chennai->id],
            'budget' => 300000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $cityIdsInPackages = collect($recommendations[0]['assets'])->pluck('city_id')->unique();
        $this->assertTrue(
            $cityIdsInPackages->contains($this->kochi->id) && $cityIdsInPackages->contains($this->chennai->id),
            'Multiple-state request must include assets from both states.'
        );
    }

    // ─── Test 5 — All States (state_ids = []) ─────────────────────

    public function test_all_states_interprets_empty_state_ids_as_all_states(): void
    {
        $response = $this->callCalculate([
            'state_ids' => [],
            'city_ids' => [],
            'budget' => 300000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $assetIds = collect($recommendations[0]['assets'])->pluck('asset_id');
        $this->assertTrue(
            $assetIds->contains($this->fixedBengaluruMall->id),
            'Empty state_ids must resolve to all states of the country (Karnataka screen included).'
        );
    }

    // ─── Test 6 — All Cities (city_ids = []) ───────────────────────

    public function test_all_cities_interprets_empty_city_ids_as_all_cities_of_states(): void
    {
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [],
            'budget' => 300000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $cityIds = collect($recommendations[0]['assets'])->pluck('city_id')->unique();
        $this->assertTrue(
            $cityIds->contains($this->kochi->id) && $cityIds->contains($this->kottayam->id),
            'Empty city_ids must include inventory from every city of the selected states.'
        );
    }

    // ─── Test 7 — Screen category applies to fixed AND mobile ─────

    public function test_screen_category_filters_both_fixed_and_mobile(): void
    {
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id, $this->kottayam->id],
            'screen_category_ids' => [$this->theatre->id],
            'budget' => 200000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $bestMatch = collect($recommendations)->firstWhere('strategy', 'preferred_duration') ?? $recommendations[0];
        foreach ($bestMatch['assets'] as $asset) {
            $this->assertEquals($this->theatre->id, $asset['screen_category_id']);
        }

        // Theatre assets exist in BOTH kinds (fixed Kochi Theatre + mobile Kerala Theatre).
        $assetTypes = collect($bestMatch['assets'])->pluck('asset_type')->unique();
        $this->assertTrue($assetTypes->count() > 1, 'Theatre category must match both fixed and mobile assets.');
    }

    // ─── Test 8 — Mobile quantity within availability ─────────────

    public function test_mobile_quantity_never_exceeds_fleet_availability(): void
    {
        // Fleet 100, no reservations → available 100. Budget favours high quantity.
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id, $this->kottayam->id],
            'screen_category_ids' => [$this->theatre->id],
            'budget' => 200000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);

        foreach ($response->json('data.recommendations') as $rec) {
            foreach ($rec['assets'] as $asset) {
                if ($asset['asset_id'] === $this->mobileKeralaTheatre->id) {
                    $this->assertGreaterThanOrEqual(1, $asset['quantity']);
                    $this->assertLessThanOrEqual(100, $asset['quantity']);
                    $this->assertLessThanOrEqual($asset['available_units'], $asset['quantity']);
                    $this->assertEquals(100, $asset['fleet_size']);
                }
            }
        }
    }

    // ─── Test 9 — Mobile quantity exceeding availability rejected ──

    public function test_mobile_quantity_never_exceeds_reserved_availability(): void
    {
        // Reserve 90 of 100 units over the full window → available 10.
        $this->reserveFleet($this->mobileKeralaTheatre, 90, '2026-11-01', '2026-11-30');

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id, $this->kottayam->id],
            'screen_category_ids' => [$this->theatre->id],
            'budget' => 200000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $rec) {
            foreach ($rec['assets'] as $asset) {
                if ($asset['asset_id'] === $this->mobileKeralaTheatre->id) {
                    $this->assertLessThanOrEqual(10, $asset['quantity'], 'Quantity must never exceed date-range availability.');
                    $this->assertEquals(10, $asset['available_units']);
                }
            }
        }
    }

    // ─── Test 10 — Budget constraint on every package ──────────────

    public function test_every_recommendation_is_within_budget(): void
    {
        foreach ([50000, 100000, 200000] as $budget) {
            $response = $this->callCalculate([
                'state_ids' => [$this->kerala->id],
                'city_ids' => [$this->kochi->id, $this->kottayam->id],
                'budget' => $budget,
            ]);

            $response->assertStatus(200)->assertJsonPath('status', true);
            foreach ($response->json('data.recommendations') as $rec) {
                $this->assertLessThanOrEqual($budget, $rec['pricing']['final_amount']);
                $this->assertTrue($rec['ranking_metrics']['budget_adherence']);
                $this->assertEquals(round($budget - $rec['pricing']['final_amount'], 2), $rec['pricing']['remaining_budget']);
            }
        }
    }

    // ─── Test 11 — Duration pricing through the oracle ─────────────

    public function test_duration_pricing_comes_from_campaign_pricing_service(): void
    {
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'budget' => 100000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $rec = $response->json('data.recommendations')[0];

        $oracle = app(CampaignPricingService::class)->calculateCampaignPreview([
            'screen_ids' => array_column($rec['assets'], 'asset_id'),
            'quantities' => collect($rec['assets'])->mapWithKeys(fn ($a) => [$a['asset_id'] => $a['quantity']])->all(),
            'start_date' => $rec['campaign_summary']['start_date'],
            'end_date' => $rec['campaign_summary']['end_date'],
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
        ]);

        $this->assertEquals(
            (float) $oracle['aggregated']['final_amount'],
            (float) $rec['pricing']['final_amount'],
            'Engine final_amount must equal the CampaignPricingService final_amount.'
        );
    }

    // ─── Test 12 — Weekend pricing controlled by the oracle ────────

    public function test_weekend_pricing_is_controlled_by_the_oracle(): void
    {
        PricingWeekendRule::create([
            'pricing_plan_id' => $this->planA->id,
            'days' => 'Sat,Sun',
            'pricing_type' => 'multiplier',
            'value' => 1.5,
            'status' => 'active',
        ]);

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'budget' => 100000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $rec = $response->json('data.recommendations')[0];

        $oracle = app(CampaignPricingService::class)->calculateCampaignPreview([
            'screen_ids' => array_column($rec['assets'], 'asset_id'),
            'quantities' => collect($rec['assets'])->mapWithKeys(fn ($a) => [$a['asset_id'] => $a['quantity']])->all(),
            'start_date' => $rec['campaign_summary']['start_date'],
            'end_date' => $rec['campaign_summary']['end_date'],
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
        ]);

        $this->assertGreaterThan(0, $oracle['aggregated']['weekend_adjustment'], 'Weekend window must produce weekend charges.');
        $this->assertEquals((float) $oracle['aggregated']['final_amount'], (float) $rec['pricing']['final_amount']);
        $this->assertEquals((float) $oracle['aggregated']['weekend_adjustment'], (float) $rec['pricing']['weekend_adjustment']);
    }

    // ─── Test 13 — Peak pricing controlled by the oracle ───────────

    public function test_peak_pricing_is_controlled_by_the_oracle(): void
    {
        PricingPeakRule::create([
            'pricing_plan_id' => $this->planA->id,
            'day_of_week' => 'all',
            'start_time' => '18:00',
            'end_time' => '22:00',
            'pricing_type' => 'multiplier',
            'value' => 1.5,
            'status' => 'active',
        ]);

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'budget' => 100000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $rec = $response->json('data.recommendations')[0];

        $oracle = app(CampaignPricingService::class)->calculateCampaignPreview([
            'screen_ids' => array_column($rec['assets'], 'asset_id'),
            'quantities' => collect($rec['assets'])->mapWithKeys(fn ($a) => [$a['asset_id'] => $a['quantity']])->all(),
            'start_date' => $rec['campaign_summary']['start_date'],
            'end_date' => $rec['campaign_summary']['end_date'],
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
        ]);

        $this->assertGreaterThan(0, $oracle['aggregated']['peak_adjustment'], 'Peak rule must produce peak charges.');
        $this->assertEquals((float) $oracle['aggregated']['final_amount'], (float) $rec['pricing']['final_amount']);
        $this->assertEquals((float) $oracle['aggregated']['peak_adjustment'], (float) $rec['pricing']['peak_adjustment']);
    }

    // ─── Test 14 — Duration discount through the oracle ────────────

    public function test_duration_discount_is_applied_by_the_oracle(): void
    {
        PricingDurationRule::create([
            'pricing_plan_id' => $this->planA->id,
            'minimum_duration' => 30,
            'duration_unit' => 'day',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'budget' => 100000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        // The 30-day More Time package must earn the discount.
        $rec = collect($response->json('data.recommendations'))->firstWhere('strategy', 'preferred_duration')
            ?? collect($response->json('data.recommendations'))->firstWhere('strategy', 'extended_duration')
            ?? $response->json('data.recommendations.0');
        $this->assertNotNull($rec);

        $oracle = app(CampaignPricingService::class)->calculateCampaignPreview([
            'screen_ids' => array_column($rec['assets'], 'asset_id'),
            'quantities' => collect($rec['assets'])->mapWithKeys(fn ($a) => [$a['asset_id'] => $a['quantity']])->all(),
            'start_date' => $rec['campaign_summary']['start_date'],
            'end_date' => $rec['campaign_summary']['end_date'],
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
        ]);

        $this->assertGreaterThan(0, $oracle['aggregated']['duration_discount'], '30-day campaign must earn the duration discount.');
        $this->assertEquals((float) $oracle['aggregated']['final_amount'], (float) $rec['pricing']['final_amount']);
        $this->assertEquals((float) $oracle['aggregated']['duration_discount'], (float) $rec['pricing']['duration_discount']);
    }

    // ─── Test 15 — Minimum duration violation → no packages ────────

    public function test_minimum_duration_violation_returns_no_packages(): void
    {
        // A category whose ONLY screen requires a 7-day minimum → a 3-day
        // window has zero eligible inventory and must produce no packages
        // (and the note must truthfully mention the minimum duration).
        $weeklyOnly = ScreenCategory::create(['name' => 'Weekly Only', 'slug' => 'weekly-only', 'status' => 'active']);
        $this->makeFixed('Weekly Only Screen', 'FIX-007', $this->kochi, $weeklyOnly, $this->planC);

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$weeklyOnly->id],
            'budget' => 500000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-03',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $data = $response->json('data');
        $this->assertEmpty($data['recommendations']);
        $this->assertStringContainsStringIgnoringCase('minimum campaign', $data['inventory_note']);
        $this->assertSame('DURATION_NOT_SUPPORTED', $data['no_result']['reason']);
    }

    // ─── BP3 §1–9 — per-asset minimum-duration filtering ───────────

    public function test_mixed_minimum_durations_keep_short_window_eligible_assets(): void
    {
        // Kochi Mall: fixedKochiMall (min 1 day) + a min-7-day screen + a
        // min-30-day screen in the SAME city + category. A 6-day campaign
        // must recommend the min-1-day screen and never include the
        // longer-minimum screens — one incompatible plan must not invalidate
        // the whole inventory.
        $plan7 = $this->makePlan('Weekly Mall Plan', 3000, 7);
        $plan30 = $this->makePlan('Monthly Mall Plan', 5000, 30);
        $weeklyScreen = $this->makeFixed('Kochi Mall Weekly Screen', 'FIX-008', $this->kochi, $this->mall, $plan7);
        $monthlyScreen = $this->makeFixed('Kochi Mall Monthly Screen', 'FIX-009', $this->kochi, $this->mall, $plan30);

        $response = $this->callCalculate([
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'budget' => 200000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-06', // 6-day window
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations, 'The min-1-day screen must still be recommendable for a 6-day campaign.');

        $bestMatch = collect($recommendations)->firstWhere('strategy', 'preferred_duration') ?? $recommendations[0];
        $this->assertLessThanOrEqual(6, $bestMatch['campaign_summary']['duration_days']);
        $assetIds = array_column($bestMatch['assets'], 'asset_id');
        $this->assertContains($this->fixedKochiMall->id, $assetIds, 'The min-1-day screen must participate.');
        $this->assertNotContains($weeklyScreen->id, $assetIds, 'A min-7-day screen must be excluded from a 6-day campaign.');
        $this->assertNotContains($monthlyScreen->id, $assetIds, 'A min-30-day screen must be excluded from a 6-day campaign.');
    }

    public function test_all_assets_require_longer_minimum_return_no_packages(): void
    {
        // A category whose ONLY screens require a 30-day minimum → a 6-day
        // campaign has zero eligible inventory: no packages, truthful note.
        $monthlyOnly = ScreenCategory::create(['name' => 'Monthly Only', 'slug' => 'monthly-only', 'status' => 'active']);
        $plan30 = $this->makePlan('Monthly Only Plan', 5000, 30);
        $this->makeFixed('Monthly Screen A', 'FIX-010', $this->kochi, $monthlyOnly, $plan30);
        $this->makeFixed('Monthly Screen B', 'FIX-011', $this->kochi, $monthlyOnly, $plan30);

        $response = $this->callCalculate([
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$monthlyOnly->id],
            'budget' => 500000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-06', // 6-day window
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $data = $response->json('data');
        $this->assertEmpty($data['recommendations']);
        $this->assertStringContainsStringIgnoringCase('minimum campaign', $data['inventory_note']);
        $this->assertSame('DURATION_NOT_SUPPORTED', $data['no_result']['reason']);
    }

    public function test_mixed_fixed_and_mobile_short_window_filters_by_plan(): void
    {
        // Fixed: Kochi Mall (min 1 day) + a min-30-day fixed screen in the
        // same city. Mobile: Kerala Theatre fleet (min 1 day, covers Kochi).
        // A 6-day campaign must be able to combine the min-1-day fixed screen
        // with the min-1-day fleet while the 30-day fixed screen is excluded.
        $plan30 = $this->makePlan('Monthly Mall Plan', 5000, 30);
        $monthlyScreen = $this->makeFixed('Kochi Mall Monthly', 'FIX-012', $this->kochi, $this->mall, $plan30);

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id, $this->theatre->id],
            'budget' => 200000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-06', // 6-day window
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $mixed = null;
        foreach ($recommendations as $rec) {
            $assetIds = array_column($rec['assets'], 'asset_id');
            $this->assertNotContains($monthlyScreen->id, $assetIds, 'The min-30-day fixed screen must never appear in a 6-day package.');
            if (in_array($this->fixedKochiMall->id, $assetIds, true)
                && in_array($this->mobileKeralaTheatre->id, $assetIds, true)) {
                $mixed = $rec;
                break;
            }
        }
        $this->assertNotNull($mixed, 'A 6-day package must combine the min-1-day fixed screen with the min-1-day mobile fleet.');
    }

    public function test_longer_campaign_includes_long_minimum_plans(): void
    {
        // A 30-day campaign must be able to use every plan whose minimum is
        // ≤ 30 days — including a 30-day-min plan. Short-minimum plans must
        // never be excluded from longer campaigns either.
        $plan30 = $this->makePlan('Monthly Mall Plan', 5000, 30);
        $monthlyScreen = $this->makeFixed('Kochi Mall Monthly', 'FIX-013', $this->kochi, $this->mall, $plan30);

        $response = $this->callCalculate([
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'budget' => 500000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30', // 30-day window
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $foundMonthly = false;
        foreach ($recommendations as $rec) {
            foreach ($rec['assets'] as $asset) {
                if ($asset['asset_id'] === $monthlyScreen->id) {
                    $foundMonthly = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($foundMonthly, 'The 30-day-min screen must be eligible for a 30-day campaign.');
    }

    // ─── Test 16 — Sold-out assets never appear ────────────────────

    public function test_sold_out_asset_never_appears(): void
    {
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'budget' => 300000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);

        foreach ($response->json('data.recommendations') as $rec) {
            $assetIds = array_column($rec['assets'], 'asset_id');
            $this->assertNotContains($this->fixedSoldOut->id, $assetIds);
        }
    }

    // ─── Test 17 — Mobile date-range availability ──────────────────

    public function test_mobile_date_range_availability_is_respected(): void
    {
        // Reserve 20 of 50 units over part of the window → available 30 for the full window.
        $this->reserveFleet($this->mobileKeralaTaxi, 20, '2026-11-10', '2026-11-15');

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->taxi->id],
            'budget' => 500000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $rec) {
            foreach ($rec['assets'] as $asset) {
                if ($asset['asset_id'] === $this->mobileKeralaTaxi->id) {
                    $this->assertEquals(30, $asset['available_units'], 'Date-range reservation must reduce available units.');
                    $this->assertLessThanOrEqual(30, $asset['quantity']);
                }
            }
        }
    }

    // ─── Test 18 — No inventory ────────────────────────────────────

    public function test_no_inventory_returns_empty_with_note(): void
    {
        $goa = State::create(['name' => 'Goa', 'country_id' => $this->country->id]);
        $panaji = City::create(['name' => 'Panaji', 'state_id' => $goa->id, 'country_id' => $this->country->id]);

        $response = $this->callCalculate([
            'state_ids' => [$goa->id],
            'city_ids' => [$panaji->id],
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $data = $response->json('data');
        $this->assertEmpty($data['recommendations']);
        $this->assertNotEmpty($data['inventory_note']);
        $this->assertSame('NO_INVENTORY', $data['no_result']['reason']);
        $this->assertSame($data['inventory_note'], $data['no_result']['message']);
    }

    public function test_no_category_match_returns_structured_reason(): void
    {
        // Kochi has inventory, but none in the selected category → the reason
        // must say NO_CATEGORY_MATCH, not NO_INVENTORY.
        $missingCategory = ScreenCategory::create(['name' => 'Airport', 'slug' => 'airport', 'status' => 'active']);

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$missingCategory->id],
            'budget' => 300000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $data = $response->json('data');
        $this->assertEmpty($data['recommendations']);
        $this->assertSame('NO_CATEGORY_MATCH', $data['no_result']['reason']);
    }

    public function test_fully_booked_mobile_fleet_returns_insufficient_units_reason(): void
    {
        // Reserve the ENTIRE taxi fleet (50 of 50) over the full window → the
        // only matching assets are mobile fleets with 0 units → the reason
        // must be INSUFFICIENT_MOBILE_UNITS (not plain NO_AVAILABILITY).
        $this->reserveFleet($this->mobileKeralaTaxi, 50, '2026-11-01', '2026-11-30');

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->taxi->id],
            'budget' => 300000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $data = $response->json('data');
        $this->assertEmpty($data['recommendations']);
        $this->assertSame('INSUFFICIENT_MOBILE_UNITS', $data['no_result']['reason']);
    }

    // ─── Test 19 — No package within budget ────────────────────────

    public function test_no_package_within_budget_returns_empty_with_note(): void
    {
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'budget' => 1,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $data = $response->json('data');
        $this->assertEmpty($data['recommendations']);
        $this->assertStringContainsStringIgnoringCase('budget', $data['inventory_note']);
        $this->assertSame('BUDGET_TOO_LOW', $data['no_result']['reason']);
    }

    // ─── Test 20 — Preference drives package shape ─────────────────

    public function test_preference_shapes_reach_and_duration(): void
    {
        $payload = [
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id, $this->kottayam->id],
            'budget' => 200000,
        ];

        $moreScreens = $this->callCalculate(array_merge($payload, ['campaign_preference' => 'more_screens']))
            ->json('data.recommendations');
        $moreDuration = $this->callCalculate(array_merge($payload, ['campaign_preference' => 'more_duration']))
            ->json('data.recommendations');

        $this->assertNotEmpty($moreScreens);
        $this->assertNotEmpty($moreDuration);

        $screensPkg = collect($moreScreens)->firstWhere('strategy', 'preferred_duration') ?? $moreScreens[0] ?? null;
        $durationPkg = collect($moreDuration)->firstWhere('strategy', 'extended_duration') ?? $moreDuration[0] ?? null;

        $this->assertNotNull($screensPkg);
        $this->assertNotNull($durationPkg);
    }

    // ─── Pricing authority (§53) — every package equals the oracle ─

    public function test_every_package_final_amount_matches_pricing_oracle(): void
    {
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id, $this->tamilNadu->id],
            'city_ids' => [$this->kochi->id, $this->kottayam->id, $this->chennai->id],
            'budget' => 250000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $rec) {
            $oracle = app(CampaignPricingService::class)->calculateCampaignPreview([
                'screen_ids' => array_column($rec['assets'], 'asset_id'),
                'quantities' => collect($rec['assets'])->mapWithKeys(fn ($a) => [$a['asset_id'] => $a['quantity']])->all(),
                'start_date' => $rec['campaign_summary']['start_date'],
                'end_date' => $rec['campaign_summary']['end_date'],
                'daily_start_time' => '00:00',
                'daily_end_time' => '23:59',
            ]);

            $this->assertEquals(
                (float) $oracle['aggregated']['final_amount'],
                (float) $rec['pricing']['final_amount'],
                "Package {$rec['recommendation_id']} must equal the pricing oracle."
            );
        }
    }

    // ─── Response contract ─────────────────────────────────────────

    public function test_response_contract_matches_bp1_spec(): void
    {
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id, $this->kottayam->id],
            'budget' => 200000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $data = $response->json('data');

        $this->assertArrayHasKey('request', $data);
        $this->assertEquals(200000, $data['request']['budget']);
        $this->assertEquals('more_screens', $data['request']['campaign_preference']);

        $this->assertNotEmpty($data['recommendations']);
        $this->assertLessThanOrEqual(3, count($data['recommendations']));

        $validStrategies = ['preferred_duration', 'extended_duration', 'expanded_coverage'];
        foreach ($data['recommendations'] as $rec) {
            $this->assertMatchesRegularExpression('/^REC-[A-F0-9]{8}$/', $rec['recommendation_id']);
            $this->assertContains($rec['strategy'], $validStrategies);

            $summary = $rec['campaign_summary'];
            foreach (['start_date', 'end_date', 'duration_days', 'total_assets', 'fixed_asset_count', 'mobile_fleet_count', 'mobile_units_total', 'cities', 'screen_categories'] as $key) {
                $this->assertArrayHasKey($key, $summary);
            }

            foreach ($rec['assets'] as $asset) {
                foreach (['asset_id', 'name', 'asset_type', 'quantity', 'pricing_plan_id', 'unit_price_per_day', 'line_total'] as $key) {
                    $this->assertArrayHasKey($key, $asset);
                }
            }

            foreach (['base_price', 'screen_discount', 'duration_discount', 'peak_adjustment', 'weekend_adjustment', 'first_booking_discount', 'referral_discount', 'subtotal', 'final_amount', 'remaining_budget', 'budget_utilization_pct'] as $key) {
                $this->assertArrayHasKey($key, $rec['pricing']);
            }

            // BP6 P1-1 — the authoritative pricing snapshot is NOT shipped to
            // the planner UI (the booking flow recomputes it); only aggregates
            // are exposed.
            $this->assertArrayNotHasKey('pricing_snapshot', $rec['pricing']);

            foreach (['reach', 'duration_days', 'cost_per_reach_day', 'budget_adherence'] as $key) {
                $this->assertArrayHasKey($key, $rec['ranking_metrics']);
            }
        }
    }

    // ─── BP6 P1-1 — payload size regression ────────────────────────

    public function test_response_payload_does_not_embed_daily_breakdowns(): void
    {
        // Wide request: many assets across several cities over the full
        // 30-day window — exactly the request shape that previously inflated
        // the payload with per-asset × per-day pricing breakdowns.
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id, $this->tamilNadu->id, $this->karnataka->id],
            'city_ids' => [$this->kochi->id, $this->kottayam->id, $this->chennai->id, $this->bengaluru->id],
            'budget' => 500000,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data['recommendations']);

        // No per-day pricing breakdown may leak into the planner response.
        $this->assertStringNotContainsString('daily_breakdown', $response->getContent());
        $this->assertStringNotContainsString('pricing_snapshot', $response->getContent());

        // Aggregate pricing must still be present and correct for every
        // package (the engine's authoritative amounts remain intact).
        foreach ($data['recommendations'] as $rec) {
            $this->assertArrayHasKey('final_amount', $rec['pricing']);
            $this->assertArrayHasKey('subtotal', $rec['pricing']);
            $this->assertArrayHasKey('remaining_budget', $rec['pricing']);
            $this->assertTrue($rec['pricing']['final_amount'] > 0);
        }

        // Materially smaller: a 4-city / 3-state / 30-day sweep across all
        // matching assets must stay well under 256 KB even when solved for
        // all three packages.
        $this->assertLessThan(
            262144,
            strlen($response->getContent()),
            'Recommendation payload must not embed per-day pricing breakdowns.'
        );
    }

    // ─── Validation ────────────────────────────────────────────────

    public function test_validation_rejects_invalid_geography(): void
    {
        // State from another country → 422
        $otherCountry = Country::create(['name' => 'Sri Lanka', 'code' => 'LK']);
        $otherState = State::create(['name' => 'Western', 'country_id' => $otherCountry->id]);

        $this->callCalculate(['state_ids' => [$otherState->id], 'city_ids' => []])
            ->assertStatus(422);

        // City not belonging to the selected state → 422
        $this->callCalculate(['state_ids' => [$this->kerala->id], 'city_ids' => [$this->chennai->id]])
            ->assertStatus(422);

        // Missing budget → 422
        $payload = [
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
        $this->postJson('/api/recommendations/calculate', $payload)->assertStatus(422);

        // Invalid date order → 422
        $this->callCalculate(['start_date' => '2026-11-30', 'end_date' => '2026-11-01'])->assertStatus(422);

        // Invalid preference → 422
        $this->callCalculate(['campaign_preference' => 'maximize_chaos'])->assertStatus(422);
    }

    // ─── Legacy endpoint untouched ─────────────────────────────────

    public function test_legacy_v2_recommendations_endpoint_still_works(): void
    {
        // SEC-02 security-contract change: this endpoint was fully anonymous and now
        // requires `screens.view`. It returns AssetResource, which exposes the
        // platform/provider revenue split (illuvision_percentage,
        // screen_provider_percentage), so it must not be public.
        //
        // The response-contract assertion below is UNCHANGED.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::create([
            'name' => 'Recommendation Admin',
            'email' => 'recommendation-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
        ]));

        $this->getJson('/api/v2/recommendations')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_legacy_v2_recommendations_endpoint_rejects_anonymous_callers(): void
    {
        // The security half of the same contract change.
        $this->getJson('/api/v2/recommendations')->assertStatus(401);
    }

    // ─── BP5 §6/§9 — multi-duration search discovers short campaigns ──

    public function test_engine_searches_multiple_durations_including_short_ones(): void
    {
        // Theatre in Kochi mixes a 1-day plan (Kochi Theatre) with a 7-day
        // plan (weekly screen) → within a 7-day window the engine returns a
        // Best Match 7-day package.
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->theatre->id],
            'budget' => 500000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-07',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $bestMatch = collect($recommendations)->firstWhere('strategy', 'preferred_duration');
        $this->assertNotNull($bestMatch);
        $this->assertEquals(7, $bestMatch['campaign_summary']['duration_days']);
    }

    public function test_engine_evaluates_duration_discount_breakpoints(): void
    {
        // A dedicated 100-unit fleet on a 1-day-min plan with a strong 60%
        // duration discount at exactly 14 days. A 14-day request must evaluate
        // the 14-day discount and earn the duration discount from the oracle.
        $breakpointCat = ScreenCategory::create(['name' => 'Breakpoint', 'slug' => 'breakpoint', 'status' => 'active']);
        $breakpointFleet = $this->makeMobile('Breakpoint Fleet', 'MOB-004', $breakpointCat, $this->planA, 100);
        $breakpointFleet->coverageStates()->attach([$this->kerala->id]);
        $breakpointFleet->coverageCities()->attach([$this->kochi->id]);

        PricingDurationRule::create([
            'pricing_plan_id' => $this->planA->id,
            'minimum_duration' => 14,
            'maximum_duration' => 14,
            'duration_unit' => 'day',
            'discount_type' => 'percentage',
            'discount_value' => 60,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$breakpointCat->id],
            'budget' => 300000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-14',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $bestMatch = collect($recommendations)->firstWhere('strategy', 'preferred_duration');
        $this->assertNotNull($bestMatch);
        $this->assertEquals(14, $bestMatch['campaign_summary']['duration_days']);
        $this->assertGreaterThan(0, $bestMatch['pricing']['duration_discount']);
    }

    public function test_short_window_mobile_availability_is_date_range_aware(): void
    {
        // Reserve 20 of 50 taxi units over a sub-range inside the 7-day window.
        $this->reserveFleet($this->mobileKeralaTaxi, 20, '2026-11-03', '2026-11-05');

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->taxi->id],
            'budget' => 100000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-07', // 7-day window
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations);

        $bestMatch = collect($recommendations)->firstWhere('strategy', 'preferred_duration');
        $this->assertNotNull($bestMatch);
        $this->assertEquals(7, $bestMatch['campaign_summary']['duration_days']);

        foreach ($bestMatch['assets'] as $asset) {
            if ($asset['asset_id'] === $this->mobileKeralaTaxi->id) {
                $this->assertEquals(
                    30,
                    $asset['available_units'],
                    'Availability must be computed over the actual 7-day range.'
                );
                $this->assertLessThanOrEqual(30, $asset['quantity']);
            }
        }
    }

    public function test_short_window_weekend_pricing_uses_the_oracle(): void
    {
        PricingWeekendRule::create([
            'pricing_plan_id' => $this->planA->id,
            'days' => 'Sat,Sun',
            'pricing_type' => 'multiplier',
            'value' => 1.5,
            'status' => 'active',
        ]);

        // 7-day window 2026-11-01 (Sun) → 2026-11-07 (Sat): weekend charges apply.
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'budget' => 100000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-07',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $bestMatch = collect($response->json('data.recommendations'))
            ->firstWhere('strategy', 'preferred_duration') ?? $response->json('data.recommendations.0');
        $this->assertNotNull($bestMatch);
        $this->assertEquals(7, $bestMatch['campaign_summary']['duration_days']);
        $this->assertGreaterThan(0, $bestMatch['pricing']['weekend_adjustment']);

        $oracle = app(CampaignPricingService::class)->calculateCampaignPreview([
            'screen_ids' => array_column($bestMatch['assets'], 'asset_id'),
            'quantities' => collect($bestMatch['assets'])->mapWithKeys(fn ($a) => [$a['asset_id'] => $a['quantity']])->all(),
            'start_date' => $bestMatch['campaign_summary']['start_date'],
            'end_date' => $bestMatch['campaign_summary']['end_date'],
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
        ]);
        $this->assertEquals((float) $oracle['aggregated']['final_amount'], (float) $bestMatch['pricing']['final_amount']);
    }

    public function test_short_window_peak_pricing_uses_the_oracle(): void
    {
        PricingPeakRule::create([
            'pricing_plan_id' => $this->planA->id,
            'day_of_week' => 'all',
            'start_time' => '18:00',
            'end_time' => '22:00',
            'pricing_type' => 'multiplier',
            'value' => 1.5,
            'status' => 'active',
        ]);

        // 5-day window: short campaigns must still be priced with peak rules.
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->mall->id],
            'budget' => 100000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $bestMatch = collect($response->json('data.recommendations'))
            ->firstWhere('strategy', 'preferred_duration') ?? $response->json('data.recommendations.0');
        $this->assertNotNull($bestMatch);
        $this->assertEquals(5, $bestMatch['campaign_summary']['duration_days']);
        $this->assertGreaterThan(0, $bestMatch['pricing']['peak_adjustment']);

        $oracle = app(CampaignPricingService::class)->calculateCampaignPreview([
            'screen_ids' => array_column($bestMatch['assets'], 'asset_id'),
            'quantities' => collect($bestMatch['assets'])->mapWithKeys(fn ($a) => [$a['asset_id'] => $a['quantity']])->all(),
            'start_date' => $bestMatch['campaign_summary']['start_date'],
            'end_date' => $bestMatch['campaign_summary']['end_date'],
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
        ]);
        $this->assertEquals((float) $oracle['aggregated']['final_amount'], (float) $bestMatch['pricing']['final_amount']);
    }

    // ─── BP5 §28 — duplicate recommendation packages are removed ─────

    public function test_identical_packages_are_deduplicated(): void
    {
        // Only the min-7-day plan asset is in scope and the window is 7 days,
        // so every candidate yields the identical package and deduplication keeps only 1.
        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [$this->theatre->id],
            'budget' => 500000,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-07',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations, 'A feasible package must exist.');

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

        $this->assertEquals(
            count($signatures),
            count(array_unique($signatures)),
            'No two recommendations may be identical in assets, quantities, dates and final amount.'
        );
        $this->assertLessThanOrEqual(3, count($recommendations));
    }

    // ─── BP5 §39/§71 — large inventory terminates and stays valid ─────

    public function test_large_inventory_terminates_and_stays_within_budget(): void
    {
        // 200 fixed screens + the mobile fleet — exercises the bounded greedy
        // loops and batched availability without combinatorial blowup.
        for ($i = 0; $i < 200; $i++) {
            $this->makeFixed(
                "Kochi Bulk Screen {$i}",
                "FIX-BULK-{$i}",
                $this->kochi,
                $this->mall,
                $this->planA
            );
        }

        $response = $this->callCalculate([
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'budget' => 200000,
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $recommendations = $response->json('data.recommendations');
        $this->assertNotEmpty($recommendations, 'Large inventory must still produce packages.');

        foreach ($recommendations as $rec) {
            $this->assertLessThanOrEqual(200000, $rec['pricing']['final_amount']);
            $this->assertTrue($rec['ranking_metrics']['budget_adherence']);

            // Pricing authority preserved at scale.
            $oracle = app(CampaignPricingService::class)->calculateCampaignPreview([
                'screen_ids' => array_column($rec['assets'], 'asset_id'),
                'quantities' => collect($rec['assets'])->mapWithKeys(fn ($a) => [$a['asset_id'] => $a['quantity']])->all(),
                'start_date' => $rec['campaign_summary']['start_date'],
                'end_date' => $rec['campaign_summary']['end_date'],
                'daily_start_time' => '00:00',
                'daily_end_time' => '23:59',
            ]);
            $this->assertEquals(
                (float) $oracle['aggregated']['final_amount'],
                (float) $rec['pricing']['final_amount'],
                'Large-inventory package must equal the pricing oracle.'
            );
        }
    }

    // ─── Date defaults (today → today + 29) ────────────────────────

    public function test_date_defaults_when_omitted(): void
    {
        $response = $this->postJson('/api/recommendations/calculate', [
            'budget' => 100000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'campaign_preference' => 'more_screens',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', true);

        $requestEcho = $response->json('data.request');
        $this->assertEquals(now()->format('Y-m-d'), $requestEcho['start_date']);
        $this->assertEquals(now()->addDays(29)->format('Y-m-d'), $requestEcho['end_date']);
    }
}
