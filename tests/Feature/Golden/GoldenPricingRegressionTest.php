<?php

namespace Tests\Feature\Golden;

use App\Services\CampaignPricingService;
use App\Services\PricingEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoldenPricingRegressionTest extends TestCase
{
    use RefreshDatabase, GoldenTestSetupTrait;

    protected CampaignPricingService $campaignPricingService;
    protected PricingEngine $pricingEngine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupGoldenEnvironment();
        $this->campaignPricingService = app(CampaignPricingService::class);
        $this->pricingEngine = app(PricingEngine::class);
    }

    /**
     * CASE-PRICING-001: Single screen / one day
     */
    public function test_case_pricing_001_single_screen_one_day(): void
    {
        $startDate = '2026-10-01'; // Thursday
        $endDate = '2026-10-01';

        $result = $this->campaignPricingService->calculateCampaignPreview([
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_start_time' => '09:00',
            'daily_end_time' => '21:00',
        ]);

        $this->assertEquals(1, $result['campaign_summary']['total_days']);
        $this->assertEquals(1, $result['campaign_summary']['total_screens']);
        $this->assertEquals(1000.00, $result['aggregated']['base_price']);
        $this->assertEquals(0.00, $result['aggregated']['combined_discount']);
        $this->assertEquals(1000.00, $result['aggregated']['final_amount']);
    }

    /**
     * CASE-PRICING-002: Single screen / seven days (5% duration discount applied)
     */
    public function test_case_pricing_002_single_screen_seven_days(): void
    {
        $startDate = '2026-10-01';
        $endDate = '2026-10-07'; // 7 days

        $result = $this->campaignPricingService->calculateCampaignPreview([
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_start_time' => '09:00',
            'daily_end_time' => '21:00',
        ]);

        // Base price: 7 days * 1000 = 7000
        // Duration discount: 5% of 7000 = 350
        // Final: 7000 - 350 = 6650
        $this->assertEquals(7, $result['campaign_summary']['total_days']);
        $this->assertEquals(7000.00, $result['aggregated']['base_price']);
        $this->assertEquals(350.00, $result['aggregated']['duration_discount']);
        $this->assertEquals(6650.00, $result['aggregated']['final_amount']);
    }

    /**
     * CASE-PRICING-003: Single screen / thirty days (10% duration discount applied)
     */
    public function test_case_pricing_003_single_screen_thirty_days(): void
    {
        $startDate = '2026-10-01';
        $endDate = '2026-10-30'; // 30 days

        $result = $this->campaignPricingService->calculateCampaignPreview([
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_start_time' => '09:00',
            'daily_end_time' => '21:00',
        ]);

        // Base price: 30 days * 1000 = 30000
        // Duration discount: 10% of 30000 = 3000
        // Final: 30000 - 3000 = 27000
        $this->assertEquals(30, $result['campaign_summary']['total_days']);
        $this->assertEquals(30000.00, $result['aggregated']['base_price']);
        $this->assertEquals(3000.00, $result['aggregated']['duration_discount']);
        $this->assertEquals(27000.00, $result['aggregated']['final_amount']);
    }

    /**
     * CASE-PRICING-004: Multiple screens pricing aggregation
     */
    public function test_case_pricing_004_multiple_screens(): void
    {
        $startDate = '2026-10-01';
        $endDate = '2026-10-03'; // 3 days

        $result = $this->campaignPricingService->calculateCampaignPreview([
            'screen_ids' => [
                $this->fixedScreenIlluvision->id,
                $this->fixedScreenProvider->id,
            ],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_start_time' => '09:00',
            'daily_end_time' => '21:00',
        ]);

        $this->assertEquals(3, $result['campaign_summary']['total_days']);
        $this->assertEquals(2, $result['campaign_summary']['total_screens']);
        // 2 screens * 3 days * 1000 = 6000
        $this->assertEquals(6000.00, $result['aggregated']['base_price']);
        $this->assertEquals(6000.00, $result['aggregated']['final_amount']);
        $this->assertCount(2, $result['per_screen']);
    }

    /**
     * CASE-PRICING-007: Referral discount application
     */
    public function test_case_pricing_007_referral_discount(): void
    {
        $startDate = '2026-10-01';
        $endDate = '2026-10-01'; // 1 day, ₹1,000 base

        $result = $this->campaignPricingService->calculateCampaignPreview([
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_start_time' => '09:00',
            'daily_end_time' => '21:00',
            'referral_code_id' => $this->referralCode->id,
        ]);

        // Referral discount: 10% of 1000 = 100
        // Final: 1000 - 100 = 900
        $this->assertEquals(100.00, $result['aggregated']['referral_discount']);
        $this->assertEquals(900.00, $result['aggregated']['final_amount']);
        $this->assertNotNull($result['applied_referral']);
        $this->assertEquals(100.00, $result['applied_referral']['discount']);
        $this->assertEquals('percentage', $result['applied_referral']['discount_type']);
    }

    /**
     * CASE-PRICING-010: V2 PricingEngine calculatePreview output contract
     */
    public function test_case_pricing_010_v2_pricing_engine(): void
    {
        $params = [
            'items' => [
                ['asset_id' => $this->fixedScreenIlluvision->id, 'quantity' => 2],
            ],
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-05', // 5 days
        ];

        $v2Result = $this->pricingEngine->calculatePreview($params);

        // 2 qty * 5 days * 1000 unit_price = 10000 gross
        // duration < 30 days -> 0 duration discount
        $this->assertEquals(5, $v2Result['summary']['total_days']);
        $this->assertEquals(1, $v2Result['summary']['total_items']);
        $this->assertEquals(10000.00, $v2Result['pricing']['gross_total']);
        $this->assertEquals(0.00, $v2Result['pricing']['duration_discount']);
        $this->assertEquals(10000.00, $v2Result['pricing']['final_total']);
    }

    /**
     * CASE-PRICING-011: CampaignPricingService vs PricingEngine divergence characterization
     * Captures and documents the mathematical and structural divergence between both engines
     * for the EXACT same 30-day single-screen scenario.
     */
    public function test_case_pricing_011_divergence_between_v1_and_v2_engines(): void
    {
        $startDate = '2026-10-01';
        $endDate = '2026-10-30'; // 30 days

        // 1. Run v1 canonical CampaignPricingService
        $v1Result = $this->campaignPricingService->calculateCampaignPreview([
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
        ]);

        // 2. Run v2 divergent PricingEngine
        $v2Result = $this->pricingEngine->calculatePreview([
            'items' => [
                ['asset_id' => $this->fixedScreenIlluvision->id, 'quantity' => 1],
            ],
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        // Assert structural divergence
        $this->assertArrayHasKey('campaign_summary', $v1Result);
        $this->assertArrayHasKey('aggregated', $v1Result);
        $this->assertArrayHasKey('per_screen', $v1Result);

        $this->assertArrayHasKey('summary', $v2Result);
        $this->assertArrayHasKey('pricing', $v2Result);
        $this->assertArrayHasKey('line_items', $v2Result);

        // Assert calculation outputs
        // In 30-day scenario:
        // V1 base: 30000, 10% duration rule: 3000, final: 27000
        $this->assertEquals(30000.00, $v1Result['aggregated']['base_price']);
        $this->assertEquals(27000.00, $v1Result['aggregated']['final_amount']);

        // V2 gross: 30000, 10% flat duration discount: 3000, final: 27000
        $this->assertEquals(30000.00, $v2Result['pricing']['gross_total']);
        $this->assertEquals(27000.00, $v2Result['pricing']['final_total']);

        // In sub-7-day scenario (e.g. 5 days):
        // V1 has 0% duration discount because rule starts at 7 days
        // V2 has 0% duration discount because rule starts at 30 days
        $v1Short = $this->campaignPricingService->calculateCampaignPreview([
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-05',
        ]);
        $v2Short = $this->pricingEngine->calculatePreview([
            'items' => [['asset_id' => $this->fixedScreenIlluvision->id, 'quantity' => 1]],
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-05',
        ]);

        $this->assertEquals(5000.00, $v1Short['aggregated']['final_amount']);
        $this->assertEquals(5000.00, $v2Short['pricing']['final_total']);
    }
}
