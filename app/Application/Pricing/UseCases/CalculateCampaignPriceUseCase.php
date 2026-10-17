<?php

namespace App\Application\Pricing\UseCases;

use App\Domain\Pricing\Contracts\PricingEngineInterface;

/**
 * CalculateCampaignPriceUseCase
 *
 * Application use case for campaign pricing quotation and preview calculations.
 */
class CalculateCampaignPriceUseCase
{
    public function __construct(
        protected PricingEngineInterface $pricingEngine
    ) {}

    /**
     * Execute pricing calculation preview.
     *
     * @param array $payload
     * @return array
     */
    public function execute(array $payload): array
    {
        return $this->pricingEngine->calculate($payload);
    }
}
