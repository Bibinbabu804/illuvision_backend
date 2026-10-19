<?php

namespace App\Services\Freelance;

use App\Domain\Payment\Support\Money;
use App\Models\FreelancerService;
use App\Models\FreelancerServicePackage;

class ServicePricingEstimatorService
{
    /**
     * Compute a deterministic pricing estimate and breakdown.
     *
     * @param FreelancerService $service
     * @param array $params
     * @return array
     */
    public function estimate(FreelancerService $service, array $params): array
    {
        $packageId = $params['package_id'] ?? null;
        $package = null;

        if ($packageId) {
            $package = $service->packages()->find($packageId);
        }

        if (!$package) {
            $package = $service->packages()->orderBy('price')->first();
        }

        $basePrice = $package ? (float) $package->price : 500.0;
        $quantity = max((int) ($params['quantity'] ?? 1), 1);
        $packageSubtotal = $basePrice * $quantity;

        // Add-ons
        $additionalRevisions = max((int) ($params['extra_revisions'] ?? $params['additional_revisions'] ?? 0), 0);
        $revisionUnitPrice = 300.0; // standard revision price in INR
        $revisionsTotal = $additionalRevisions * $revisionUnitPrice;

        $expressDelivery = !empty($params['express_delivery']);
        $expressDeliveryFee = $expressDelivery ? 500.0 : 0.0;

        // Custom extras / add-ons
        $extras = $params['custom_addons'] ?? $params['extras'] ?? [];
        $extrasTotal = 0.0;
        $extrasBreakdown = [];
        if (is_array($extras)) {
            foreach ($extras as $extraKey => $extraVal) {
                if (is_array($extraVal)) {
                    $fee = (float) ($extraVal['price'] ?? 500.0);
                    $name = (string) ($extraVal['name'] ?? $extraKey);
                    $extrasTotal += $fee;
                    $extrasBreakdown[] = ['key' => $extraKey, 'name' => $name, 'price' => $fee];
                } elseif ($extraVal) {
                    $fee = 500.0;
                    $extrasTotal += $fee;
                    $extrasBreakdown[] = [
                        'key' => $extraKey,
                        'name' => ucwords(str_replace('_', ' ', (string) $extraKey)),
                        'price' => $fee,
                    ];
                }
            }
        }

        $grossSubtotal = $packageSubtotal + $revisionsTotal + $expressDeliveryFee + $extrasTotal;

        // Platform fee: 5% of gross
        $platformFeeRate = 0.05;
        $platformFee = round($grossSubtotal * $platformFeeRate, 2);

        // Taxes / Processing (GST 18% on platform fee or 0% depending on region)
        $taxRate = 0.18;
        $taxAmount = round($platformFee * $taxRate, 2);

        // Discount
        $discountAmount = 0.0;

        $finalPayable = round($grossSubtotal + $taxAmount, 2);
        $freelancerEarnings = round($grossSubtotal - $platformFee, 2);

        return [
            'service_id' => $service->id,
            'service_title' => $service->title,
            'package_id' => $package?->id,
            'package_name' => $package?->name ?? 'Standard',
            'package_price' => $basePrice,
            'quantity' => $quantity,
            'package_subtotal' => $packageSubtotal,
            'additional_revisions' => $additionalRevisions,
            'revisions_total' => $revisionsTotal,
            'express_delivery' => $expressDelivery,
            'express_delivery_fee' => $expressDeliveryFee,
            'extras' => $extrasBreakdown,
            'extras_total' => $extrasTotal,
            'subtotal' => $grossSubtotal,
            'gross_subtotal' => $grossSubtotal,
            'platform_fee' => $platformFee,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'freelancer_amount' => $freelancerEarnings,
            'total_amount' => $finalPayable,
            'breakdown' => [
                'package_price' => $basePrice,
                'package_subtotal' => $packageSubtotal,
                'revisions' => $revisionsTotal,
                'express_delivery' => $expressDeliveryFee,
                'extras' => $extrasTotal,
                'subtotal' => $grossSubtotal,
                'platform_fee' => $platformFee,
                'applicable_tax' => $taxAmount,
                'total' => $finalPayable,
            ],
            'currency' => 'INR',
            'amount_in_paise' => Money::toPaise($finalPayable),
        ];
    }
}
