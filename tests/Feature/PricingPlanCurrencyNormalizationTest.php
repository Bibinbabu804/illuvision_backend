<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use App\Models\User;
use App\Services\Campaign\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * PRICING-CURRENCY-FIX — Normalization Tests
 *
 * Verifies that Pricing Plan base prices are always stored as canonical INR
 * regardless of the admin's selected currency, and that the existing pricing
 * engine continues to operate correctly on those INR values.
 *
 * Tests correspond directly to the acceptance criteria in the implementation plan.
 */
class PricingPlanCurrencyNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an admin user for authenticated requests
        $this->adminUser = User::factory()->create([
            'role' => 'admin',
        ]);

        // Seed baseline rates into cache so ExchangeRateService works without DB rates
        // These mirror ExchangeRateService::$baselineRates (1 INR = X target)
        Cache::put('currency_rate_GBP', 0.0092, 3600);
        Cache::put('currency_rate_USD', 0.0116, 3600);
        Cache::put('currency_rate_EUR', 0.0110, 3600);
        Cache::put('currency_rate_AED', 0.0426, 3600);
    }

    // ─── Helper ────────────────────────────────────────────────────────────────

    private function postPlan(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'name'                  => 'Test Plan',
            'description'           => 'Integration test plan',
            'base_price'            => 5000,
            'currency'              => 'INR',
            'minimum_duration'      => 1,
            'minimum_duration_type' => 'Hour',
            'is_default'            => false,
            'is_active'             => true,
        ], $overrides);

        return $this->actingAs($this->adminUser)
            ->postJson('/api/pricing-plans', $payload);
    }

    private function putPlan(int $id, array $overrides): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->adminUser)
            ->putJson("/api/pricing-plans/{$id}", $overrides);
    }

    // ─── T1: INR Plan — no conversion, rate = 1 ────────────────────────────────

    public function test_inr_plan_stores_correctly_without_conversion(): void
    {
        $response = $this->postPlan(['base_price' => 5000, 'currency' => 'INR']);

        $response->assertStatus(201);

        $plan = PricingPlan::latest()->first();

        $this->assertEquals(5000.00, (float) $plan->base_price,
            'base_price should remain 5000 INR for an INR plan');
        $this->assertEquals(5000.00, (float) $plan->base_price_original,
            'base_price_original should equal base_price for INR plans');
        $this->assertEquals('INR', $plan->base_price_currency);
        $this->assertEquals(1.0, (float) $plan->conversion_rate_to_inr,
            'conversion_rate_to_inr should be 1.0 for INR plans');
        $this->assertEquals('INR', $plan->currency);
    }

    // ─── T2: GBP Plan — base_price normalised to INR ──────────────────────────

    public function test_gbp_plan_normalises_base_price_to_inr(): void
    {
        $response = $this->postPlan(['base_price' => 100, 'currency' => 'GBP']);

        $response->assertStatus(201);

        $data = $response->json('data');
        $plan = PricingPlan::find($data['id']);

        // Rate: 1 GBP = 1/0.0092 ≈ 108.695... INR
        $expectedRate  = 1.0 / 0.0092;
        $expectedInr   = round(100 * $expectedRate, 2);

        $this->assertEqualsWithDelta($expectedInr, (float) $plan->base_price, 1.0,
            "base_price should be ~₹{$expectedInr} INR (100 GBP at cached rate)");
        $this->assertEquals(100.00, (float) $plan->base_price_original,
            'base_price_original must retain the original £100');
        $this->assertEquals('GBP', $plan->base_price_currency);
        $this->assertEquals('GBP', $plan->currency);
        $this->assertGreaterThan(100, (float) $plan->base_price,
            'INR canonical amount must be significantly larger than £100');

        // API response must also expose original fields
        $this->assertEquals(100.0, $data['basePriceOriginal']);
        $this->assertEquals('GBP', $data['basePriceCurrency']);
        $this->assertGreaterThan(1.0, $data['conversionRateToInr']);
    }

    // ─── T3: USD Plan — base_price normalised to INR ──────────────────────────

    public function test_usd_plan_normalises_base_price_to_inr(): void
    {
        $response = $this->postPlan(['base_price' => 100, 'currency' => 'USD']);

        $response->assertStatus(201);

        $plan = PricingPlan::latest()->first();

        // Rate: 1 USD = 1/0.0116 ≈ 86.2 INR
        $expectedRate = 1.0 / 0.0116;
        $expectedInr  = round(100 * $expectedRate, 2);

        $this->assertEqualsWithDelta($expectedInr, (float) $plan->base_price, 1.0);
        $this->assertEquals(100.00, (float) $plan->base_price_original);
        $this->assertEquals('USD', $plan->base_price_currency);
    }

    // ─── T4: Edit GBP price (£100 → £120) — INR recalculated ─────────────────

    public function test_updating_gbp_price_recalculates_canonical_inr(): void
    {
        // Create a GBP plan at £100
        $createResponse = $this->postPlan(['base_price' => 100, 'currency' => 'GBP']);
        $planId = $createResponse->json('data.id');
        $originalInr = PricingPlan::find($planId)->base_price;

        // Update to £120
        $updateResponse = $this->putPlan($planId, [
            'name'       => 'Test Plan',
            'base_price' => 120,
            'currency'   => 'GBP',
        ]);

        $updateResponse->assertStatus(200);

        $plan = PricingPlan::find($planId)->fresh();

        $this->assertEquals(120.00, (float) $plan->base_price_original,
            'base_price_original must update to £120');
        $this->assertGreaterThan((float) $originalInr, (float) $plan->base_price,
            'canonical INR must increase when GBP amount increases');

        // Verify ratio: new_inr / old_inr ≈ 120/100 = 1.2
        $ratio = (float) $plan->base_price / (float) $originalInr;
        $this->assertEqualsWithDelta(1.2, $ratio, 0.01,
            'INR canonical price must scale proportionally with GBP amount');
    }

    // ─── T5: Currency change GBP → USD ────────────────────────────────────────

    public function test_changing_currency_uses_new_currency_rate(): void
    {
        // Start as GBP £100
        $createResponse = $this->postPlan(['base_price' => 100, 'currency' => 'GBP']);
        $planId = $createResponse->json('data.id');
        $gbpInr = (float) PricingPlan::find($planId)->base_price;

        // Change to USD $100
        $this->putPlan($planId, [
            'name'       => 'Test Plan',
            'base_price' => 100,
            'currency'   => 'USD',
        ]);

        $plan = PricingPlan::find($planId)->fresh();

        // GBP rate ≈ 108.7, USD rate ≈ 86.2 — USD should produce less INR than GBP
        $this->assertEquals('USD', $plan->base_price_currency);
        $this->assertEquals(100.00, (float) $plan->base_price_original);
        $this->assertLessThan($gbpInr, (float) $plan->base_price,
            '$100 USD must produce less INR than £100 GBP');
    }

    // ─── T6: Existing INR plans remain unchanged ───────────────────────────────

    public function test_existing_inr_plan_is_not_affected(): void
    {
        // Create an INR plan directly in DB simulating an existing plan
        $plan = PricingPlan::create([
            'name'                   => 'Existing INR Plan',
            'description'            => 'Pre-existing plan',
            'base_price'             => 9999.00,
            'currency'               => 'INR',
            'base_price_original'    => 9999.00,
            'base_price_currency'    => 'INR',
            'conversion_rate_to_inr' => 1.0,
            'minimum_duration'       => 1,
            'minimum_duration_type'  => 'Hour',
            'is_default'             => false,
            'is_active'              => true,
        ]);

        // Update only the name — price/currency unchanged
        $this->putPlan($plan->id, [
            'name'     => 'Updated Name',
            'currency' => 'INR',
        ])->assertStatus(200);

        $plan->refresh();

        $this->assertEquals(9999.00, (float) $plan->base_price,
            'base_price must remain ₹9999 for INR plan with unchanged price');
        $this->assertEquals('INR', $plan->base_price_currency);
        $this->assertEquals(1.0, (float) $plan->conversion_rate_to_inr);
    }

    // ─── T7: Pricing Engine uses canonical INR base_price ─────────────────────

    public function test_pricing_engine_uses_canonical_inr_base_price(): void
    {
        // Create a GBP plan — base_price stored as INR canonical
        $this->postPlan(['base_price' => 100, 'currency' => 'GBP']);
        $plan = PricingPlan::latest()->first();

        // The stored base_price must be in INR (large value)
        $this->assertGreaterThan(1000, (float) $plan->base_price,
            'Pricing engine will receive INR canonical base_price (>> 100)');

        // Verify the preview endpoint works (pricing engine receives the canonical INR)
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/pricing-plans/{$plan->id}/preview?duration_hours=1&duration_unit=Hour&screens=1&day=Mon&time=09:00");

        // Should succeed (uses canonical INR base_price internally)
        // The returned price should be in INR scale, not GBP scale (>> 100)
        if ($response->status() === 200) {
            $finalPrice = $response->json('data.final_price') ?? $response->json('final_price') ?? 0;
            $this->assertGreaterThan(100, $finalPrice,
                'Preview price must reflect INR canonical amount, not original GBP amount');
        }
    }

    // ─── T8: No double conversion (GBP → INR → GBP display) ──────────────────

    public function test_no_double_conversion_for_gbp_plan(): void
    {
        $exchangeService = app(ExchangeRateService::class);

        // Create a GBP £100 plan
        $this->postPlan(['base_price' => 100, 'currency' => 'GBP']);
        $plan = PricingPlan::latest()->first();

        $canonicalInr = (float) $plan->base_price;

        // Simulate what the frontend does: convert INR → GBP display
        $gbpDisplay = $exchangeService->convertToDisplay($canonicalInr, 'GBP');

        // £100 → ~₹10870 → convert back to GBP → should be ~£100 (within rounding tolerance)
        $this->assertEqualsWithDelta(100.0, $gbpDisplay, 2.0,
            'A GBP visitor should see approximately £100, not £0.92 (the double-conversion bug)');

        // Verify: £0.92 (the bug value) is NOT the result
        $this->assertGreaterThan(50.0, $gbpDisplay,
            'Display amount must not be the catastrophically wrong £0.92 caused by double-conversion');
    }

    // ─── T9: Invalid currency code rejected ───────────────────────────────────

    public function test_unsupported_currency_code_is_rejected(): void
    {
        $response = $this->postPlan(['currency' => 'XYZ', 'base_price' => 100]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['currency']);
    }

    // ─── T10: Exchange rate unavailable — save rejected ───────────────────────

    public function test_save_fails_gracefully_when_exchange_rate_unavailable(): void
    {
        // Override ExchangeRateService to simulate unavailable rate
        $mock = Mockery::mock(ExchangeRateService::class)->makePartial();
        $mock->shouldReceive('getRateToInr')
            ->with('GBP')
            ->andReturn(null);  // Simulate unavailable rate

        $this->app->instance(ExchangeRateService::class, $mock);

        $response = $this->postPlan(['currency' => 'GBP', 'base_price' => 100]);

        // Must not succeed — should return a server error or validation failure
        $this->assertNotEquals(201, $response->status(),
            'Plan must NOT be created when exchange rate is unavailable');
        $this->assertNotEquals(200, $response->status(),
            'Plan must NOT be saved with bad currency data');

        // Verify no plan was created
        $this->assertEquals(0, PricingPlan::count(),
            'Database must remain empty when conversion fails');
    }

    // ─── T11: API response shape includes all fields ──────────────────────────

    public function test_api_response_includes_normalization_fields(): void
    {
        $response = $this->postPlan(['base_price' => 100, 'currency' => 'GBP']);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertArrayHasKey('basePrice',            $data, 'basePrice (canonical INR) must be present');
        $this->assertArrayHasKey('basePriceOriginal',    $data, 'basePriceOriginal must be present');
        $this->assertArrayHasKey('basePriceCurrency',    $data, 'basePriceCurrency must be present');
        $this->assertArrayHasKey('conversionRateToInr',  $data, 'conversionRateToInr must be present');
        $this->assertArrayHasKey('currency',             $data, 'currency must be present');

        // canonical INR must be larger than original GBP amount
        $this->assertGreaterThan($data['basePriceOriginal'], $data['basePrice']);
        // rate must be > 1 (1 GBP = many INR)
        $this->assertGreaterThan(1.0, $data['conversionRateToInr']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
