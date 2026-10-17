<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\CampaignPricingEngine;
use App\Services\Campaign\LoyaltyService;
use App\Services\PricingRuleEngine;
use App\Services\Pricing\DailyPricingCalculator;
use App\Services\Pricing\FirstBookingDiscountPricingCalculator;
use App\Services\Pricing\ReferralPricingCalculator;
use App\Services\Pricing\ScreenPricingResolver;
use App\Services\SettingsService;
use Tests\TestCase;

class CampaignPricingEngineTest extends TestCase
{
    protected CampaignPricingEngine $engine;
    protected $screenPricingResolver;
    protected $dailyPricingCalculator;
    protected $referralPricingCalculator;
    protected $firstBookingDiscountPricingCalculator;
    protected $pricingRuleEngine;
    protected $loyaltyService;
    protected $settingsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->screenPricingResolver = $this->createMock(ScreenPricingResolver::class);
        $this->dailyPricingCalculator = $this->createMock(DailyPricingCalculator::class);
        $this->referralPricingCalculator = $this->createMock(ReferralPricingCalculator::class);
        $this->firstBookingDiscountPricingCalculator = $this->createMock(FirstBookingDiscountPricingCalculator::class);
        $this->pricingRuleEngine = $this->createMock(PricingRuleEngine::class);
        $this->loyaltyService = $this->createMock(LoyaltyService::class);
        $this->settingsService = $this->createMock(SettingsService::class);

        $this->engine = new CampaignPricingEngine(
            $this->screenPricingResolver,
            $this->dailyPricingCalculator,
            $this->referralPricingCalculator,
            $this->firstBookingDiscountPricingCalculator,
            $this->pricingRuleEngine,
            $this->loyaltyService,
            $this->settingsService
        );
    }

    public function test_convert_min_duration_to_days_minute(): void
    {
        $this->assertEquals(0.5, $this->engine->convertMinDurationToDays(720, 'minutes'));
        $this->assertEquals(1.0, $this->engine->convertMinDurationToDays(1440, 'min'));
    }

    public function test_convert_min_duration_to_days_hour(): void
    {
        $this->assertEquals(0.5, $this->engine->convertMinDurationToDays(12, 'hour'));
        $this->assertEquals(1.0, $this->engine->convertMinDurationToDays(24, 'hours'));
    }

    public function test_convert_min_duration_to_days_day(): void
    {
        $this->assertEquals(1.0, $this->engine->convertMinDurationToDays(1, 'day'));
        $this->assertEquals(7.0, $this->engine->convertMinDurationToDays(7, 'days'));
    }

    public function test_convert_min_duration_to_days_week(): void
    {
        $this->assertEquals(7.0, $this->engine->convertMinDurationToDays(1, 'week'));
        $this->assertEquals(14.0, $this->engine->convertMinDurationToDays(2, 'weeks'));
    }

    public function test_convert_min_duration_to_days_month(): void
    {
        $this->assertEquals(30.0, $this->engine->convertMinDurationToDays(1, 'month'));
        $this->assertEquals(60.0, $this->engine->convertMinDurationToDays(2, 'months'));
    }

    public function test_calculate_orchestrates_pricing_components_deterministically(): void
    {
        $this->dailyPricingCalculator->expects($this->once())
            ->method('generateCampaignDays')
            ->with('2026-10-01', '2026-10-01')
            ->willReturn([
                [
                    'date' => '2026-10-01',
                    'day_of_week' => 'Thursday',
                    'is_weekend' => false,
                ]
            ]);

        $screenPlan = new \App\Models\PricingPlan();
        $screenPlan->id = 1;
        $screenPlan->name = 'Standard Plan';

        $this->screenPricingResolver->expects($this->once())
            ->method('resolveMany')
            ->with([1])
            ->willReturn(collect([1 => $screenPlan]));

        $this->pricingRuleEngine->expects($this->once())
            ->method('calculate')
            ->willReturn([
                'base_price' => 100.0,
                'discount_amount' => 0.0,
                'screen_discount_amount' => 0.0,
                'duration_discount_amount' => 0.0,
                'peak_amount' => 0.0,
                'weekend_amount' => 0.0,
                'final_price' => 100.0,
                'daily_breakdown' => [
                    [
                        'date' => '2026-10-01',
                        'day_of_week' => 'Thursday',
                        'runtime_hours' => 12.0,
                        'base_price' => 100.0,
                        'peak_charge' => 0.0,
                        'weekend_charge' => 0.0,
                        'day_subtotal' => 100.0,
                    ]
                ],
                'applied_rules' => [],
            ]);

        $params = [
            'screen_ids' => [1],
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-01',
            'daily_start_time' => '08:00',
            'daily_end_time' => '20:00',
        ];

        $result = $this->engine->calculate($params);

        $this->assertArrayHasKey('campaign_summary', $result);
        $this->assertArrayHasKey('per_screen', $result);
        $this->assertArrayHasKey('aggregated', $result);
        $this->assertEquals(100.0, $result['aggregated']['final_total']);
    }
}
