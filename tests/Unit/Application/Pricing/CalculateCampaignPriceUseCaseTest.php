<?php

namespace Tests\Unit\Application\Pricing;

use App\Application\Pricing\UseCases\CalculateCampaignPriceUseCase;
use App\Domain\Pricing\Contracts\PricingEngineInterface;
use Tests\TestCase;

class CalculateCampaignPriceUseCaseTest extends TestCase
{
    public function test_execute_delegates_to_pricing_engine(): void
    {
        $mockEngine = $this->createMock(PricingEngineInterface::class);
        $payload = [
            'screen_ids' => [1, 2],
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-07',
        ];

        $expectedResult = [
            'total_amount' => 5000.00,
            'breakdown' => ['subtotal' => 5000.00],
        ];

        $mockEngine->expects($this->once())
            ->method('calculate')
            ->with($payload)
            ->willReturn($expectedResult);

        $useCase = new CalculateCampaignPriceUseCase($mockEngine);
        $result = $useCase->execute($payload);

        $this->assertEquals($expectedResult, $result);
    }
}
