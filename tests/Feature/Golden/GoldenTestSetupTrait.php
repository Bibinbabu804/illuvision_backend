<?php

namespace Tests\Feature\Golden;

use App\Enums\AssetType;
use App\Domain\Pricing\Enums\PricingUnit;
use App\Models\AdvertisementCategory;
use App\Models\City;
use App\Models\Country;
use App\Models\Partner;
use App\Models\PricingDurationRule;
use App\Models\PricingPlan;
use App\Models\PricingScreenRule;
use App\Models\ReferralCode;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenProvider;
use App\Models\State;
use App\Models\User;

trait GoldenTestSetupTrait
{
    protected Country $country;
    protected State $state;
    protected City $city;
    protected ScreenCategory $screenCategory;
    protected AdvertisementCategory $adCategory;
    protected PricingPlan $standardPlan;
    protected ScreenProvider $providerAlpha;
    protected Screen $fixedScreenIlluvision;
    protected Screen $fixedScreenProvider;
    protected Screen $mobileFleetScreen;
    protected User $adminUser;
    protected User $customerUser;
    protected User $partnerUser;
    protected Partner $partner;
    protected ReferralCode $referralCode;

    protected function setupGoldenEnvironment(): void
    {
        // 1. Geography & Categories
        $this->country = Country::firstOrCreate(
            ['code' => 'IN'],
            [
                'name' => 'India',
                'phone_code' => '+91',
                'currency_code' => 'INR',
                'currency_symbol' => '₹',
                'status' => 'active',
            ]
        );

        $this->state = State::firstOrCreate(
            ['name' => 'Maharashtra', 'country_id' => $this->country->id]
        );

        $this->city = City::firstOrCreate(
            ['name' => 'Mumbai', 'country_id' => $this->country->id],
            ['state_id' => $this->state->id, 'status' => 'active']
        );

        $this->screenCategory = ScreenCategory::firstOrCreate(
            ['slug' => 'digital-billboard'],
            ['name' => 'Digital Billboard']
        );

        $this->adCategory = AdvertisementCategory::firstOrCreate(
            ['slug' => 'commercial-retail'],
            ['name' => 'Commercial Retail', 'status' => 'active']
        );

        // 2. Standard Pricing Plan (Base ₹1,000 / day)
        $this->standardPlan = PricingPlan::create([
            'name' => 'Golden Standard Daily Plan',
            'description' => 'Baseline 1000 INR per day plan with duration discounts',
            'base_price' => 1000.00,
            'currency' => 'INR',
            'base_price_original' => 1000.00,
            'base_price_currency' => 'INR',
            'conversion_rate_to_inr' => 1.00000000,
            'minimum_duration' => 1,
            'minimum_duration_type' => 'day',
            'is_default' => true,
            'is_active' => true,
        ]);

        // Duration Rule: 7+ days -> 5% discount, 30+ days -> 10% discount
        PricingDurationRule::create([
            'pricing_plan_id' => $this->standardPlan->id,
            'minimum_duration' => 7,
            'maximum_duration' => 29,
            'duration_unit' => 'day',
            'discount_type' => 'percentage',
            'discount_value' => 5.00,
            'sort_order' => 1,
            'status' => 'active',
        ]);

        PricingDurationRule::create([
            'pricing_plan_id' => $this->standardPlan->id,
            'minimum_duration' => 30,
            'maximum_duration' => 365,
            'duration_unit' => 'day',
            'discount_type' => 'percentage',
            'discount_value' => 10.00,
            'sort_order' => 2,
            'status' => 'active',
        ]);

        // 3. Screen Provider
        $this->providerAlpha = ScreenProvider::create([
            'provider_name' => 'Golden Prime Media Ltd',
            'business_name' => 'Golden Prime Media Pvt Ltd',
            'contact_person' => 'Sunil Verma',
            'email' => 'provider@goldenprime.com',
            'phone' => '9820011223',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'status' => 'approved',
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
        ]);

        // 4. Screens
        // A: Fixed Illuvision Screen (100% Platform)
        $this->fixedScreenIlluvision = Screen::create([
            'name' => 'Golden Marine Drive LED',
            'screen_code' => 'GLD-FIX-01',
            'screen_type' => 'digital_billboard',
            'asset_type' => AssetType::FIXED,
            'pricing_unit' => PricingUnit::PER_SCREEN,
            'screen_category_id' => $this->screenCategory->id,
            'location_name' => 'Marine Drive Promenade, Mumbai',
            'pricing_plan_id' => $this->standardPlan->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'screen_provider_id' => null,
            'illuvision_percentage' => 100.00,
            'screen_provider_percentage' => 0.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // B: Fixed Provider Screen (80% Provider, 20% Illuvision)
        $this->fixedScreenProvider = Screen::create([
            'name' => 'Golden BKC Tower Display',
            'screen_code' => 'GLD-PRV-02',
            'screen_type' => 'digital_billboard',
            'asset_type' => AssetType::FIXED,
            'pricing_unit' => PricingUnit::PER_SCREEN,
            'screen_category_id' => $this->screenCategory->id,
            'location_name' => 'Bandra Kurla Complex, Mumbai',
            'pricing_plan_id' => $this->standardPlan->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerAlpha->id,
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // C: Mobile Fleet Screen (10 fleet size)
        $this->mobileFleetScreen = Screen::create([
            'name' => 'Golden City Mobile Vans',
            'screen_code' => 'GLD-FLT-03',
            'screen_type' => 'mobile_van',
            'asset_type' => AssetType::MOBILE_VAN,
            'pricing_unit' => PricingUnit::PER_VEHICLE,
            'fleet_size' => 10,
            'screen_category_id' => $this->screenCategory->id,
            'location_name' => 'Greater Mumbai Fleet',
            'pricing_plan_id' => $this->standardPlan->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'screen_provider_id' => null,
            'illuvision_percentage' => 100.00,
            'screen_provider_percentage' => 0.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        // 5. Users
        $this->adminUser = User::factory()->create([
            'name' => 'Super Administrator',
            'email' => 'admin@illuvision.net',
            'role' => 'superadmin',
            'status' => 'active',
        ]);

        $this->customerUser = User::factory()->create([
            'name' => 'Aarav Advertiser',
            'email' => 'aarav@advertiser.com',
            'role' => 'user',
            'status' => 'active',
        ]);

        $this->partnerUser = User::factory()->create([
            'name' => 'Pooja Partner',
            'email' => 'pooja@agency.com',
            'role' => 'partner',
            'status' => 'active',
        ]);

        $this->partner = Partner::create([
            'user_id' => $this->partnerUser->id,
            'name' => 'Golden Agency Partners',
            'contact_person' => 'Pooja Partner',
            'email' => 'pooja@agency.com',
            'phone' => '9811223344',
            'city' => 'Mumbai',
            'status' => Partner::STATUS_APPROVED,
        ]);

        $this->referralCode = ReferralCode::create([
            'partner_id' => $this->partner->id,
            'code' => 'GOLDEN2026',
            'status' => 'active',
            'discount_type' => 'percentage',
            'discount_value' => 10.00, // 10% customer discount
            'commission_type' => 'percentage',
            'commission_value' => 15.00, // 15% partner commission on platform share
            'usage_limit' => 1000,
            'used_count' => 0,
            'start_date' => now()->subDay(),
            'expiry_date' => now()->addYear(),
        ]);
    }
}
