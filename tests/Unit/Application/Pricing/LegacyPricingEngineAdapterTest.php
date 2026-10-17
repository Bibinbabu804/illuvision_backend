<?php

namespace Tests\Unit\Application\Pricing;

use App\Application\Pricing\Adapters\LegacyPricingEngineAdapter;
use App\Services\PricingEngine;
use Tests\TestCase;

class LegacyPricingEngineAdapterTest extends TestCase
{
    public function test_calculate_preview_delegates_to_legacy_pricing_engine(): void
    {
        $mockEngine = $this->createMock(PricingEngine::class);

        $params = [
            'items' => [
                ['asset_id' => 1, 'quantity' => 2],
            ],
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
        ];

        $expectedResult = [
            'summary' => [
                'total_amount' => 1200.00,
                'days' => 10,
            ],
            'line_items' => [],
            'pricing' => [
                'total' => 1200.00,
            ],
        ];

        $mockEngine->expects($this->once())
            ->method('calculatePreview')
            ->with($params)
            ->willReturn($expectedResult);

        $adapter = new LegacyPricingEngineAdapter($mockEngine);
        $result = $adapter->calculatePreview($params);

        $this->assertSame($expectedResult, $result);
    }
}
