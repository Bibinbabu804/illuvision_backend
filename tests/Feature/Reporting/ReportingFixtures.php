<?php

namespace Tests\Feature\Reporting;

use App\Http\Middleware\PermissionMiddleware;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\Partner;
use App\Models\PricingPlan;
use App\Models\ReferralCode;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Screen;
use App\Models\ScreenProvider;
use App\Models\State;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Shared fixtures for the Phase 0.11 reporting regression suite.
 *
 * The reporting queries read directly from bookings / booking_screens /
 * reward_* / referral_* tables, so the fixtures deliberately write persisted
 * canonical values (revenue splits, commission ledgers) rather than invoking
 * the write-side services. That is what lets these tests assert that reporting
 * reports stored financial truth instead of recalculating it.
 */
trait ReportingFixtures
{
    protected User $admin;

    protected User $advertiser;

    protected Country $country;

    protected City $city;

    protected PricingPlan $plan;

    protected function seedReportingFixtures(): void
    {
        $this->admin = User::factory()->create([
            'role' => 'superadmin',
            'status' => 'active',
        ]);

        $this->advertiser = User::factory()->create([
            'role' => 'advertiser',
            'status' => 'active',
        ]);

        $this->country = Country::create(['name' => 'India', 'code' => 'IN', 'phone_code' => '91']);
        $state = State::create(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->city = City::create([
            'name' => 'Kochi',
            'country_id' => $this->country->id,
            'state_id' => $state->id,
        ]);

        $this->plan = PricingPlan::create([
            'name' => 'Minute Plan',
            'pricing_model' => 'dynamic',
            'base_price' => 1000,
            'screen_pricing_model' => 'fixed',
            'minimum_duration' => 30,
            'minimum_duration_type' => 'minute',
        ]);
    }

    /**
     * Create a booking whose created_at is pinned explicitly.
     *
     * Reporting filters on bookings.created_at for revenue/category/booking
     * metrics but on bookings.end_time for the screen provider report, so both
     * are settable here.
     */
    protected function makeBooking(array $attributes = []): Booking
    {
        $booking = Booking::create(array_merge([
            'user_id' => $this->advertiser->id,
            'pricing_plan_id' => $this->plan->id,
            'title' => 'Campaign',
            'campaign_title' => 'Campaign',
            'advertiser_name' => 'Advertiser Ltd',
            'start_time' => '2026-08-10 10:00:00',
            'end_time' => '2026-08-10 11:00:00',
            'price' => 1000,
            'subtotal' => 1000,
            'discount_amount' => 0,
            'referral_discount' => 0,
            'final_amount' => 1000,
            'total_screens' => 1,
            'status' => Booking::STATUS_APPROVED,
        ], $attributes));

        if (isset($attributes['created_at'])) {
            $booking->created_at = $attributes['created_at'];
            $booking->save();
        }

        return $booking;
    }

    /**
     * Create a provider-owned screen with a persisted revenue split.
     */
    protected function makeProviderScreen(
        ScreenProvider $provider,
        string $name = 'Marine Drive Screen',
        string $code = 'APX-MDR-01'
    ): Screen {
        return Screen::create([
            'name' => $name,
            'screen_code' => $code,
            'screen_type' => 'digital_billboard',
            'location_name' => 'Marine Drive Promenade',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $provider->id,
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'pricing_plan_id' => $this->plan->id,
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    protected function makeProvider(string $name = 'Apex Displays', string $business = 'Apex Media Ltd'): ScreenProvider
    {
        return ScreenProvider::create([
            'provider_name' => $name,
            'business_name' => $business,
            'business_type' => 'company',
            'email' => str($name)->slug().'@example.com',
            'phone' => '9876543210',
            'status' => 'approved',
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
        ]);
    }

    /**
     * Attach a booking line to a screen, persisting the financial split exactly
     * as the revenue distribution domain would have written it.
     */
    protected function attachBookingScreen(
        Booking $booking,
        Screen $screen,
        array $attributes = []
    ): BookingScreen {
        return BookingScreen::create(array_merge([
            'booking_id' => $booking->id,
            'screen_id' => $screen->id,
            'quantity' => 1,
            'subtotal' => 1000,
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $screen->screen_provider_id,
            'gross_revenue' => 1000,
            'platform_share' => 150,
            'provider_share' => 850,
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
            'settlement_status' => 'pending',
            'status' => 'approved',
        ], $attributes));
    }

    /**
     * Persist a partner commission ledger row.
     *
     * partner_commissions.referral_usage_id is NOT NULL, so a commission is
     * always attached to a real referral usage row.
     */
    protected function makeCommission(Partner $partner, int $referralUsageId, string $status, float $amount, string $createdAt): void
    {
        DB::table('partner_commissions')->insert([
            'partner_id' => $partner->id,
            'referral_usage_id' => $referralUsageId,
            'booking_id' => null,
            'commission_type' => 'referral',
            'amount' => $amount,
            'status' => $status,
            'generated_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    protected function makePartnerReferral(string $code = 'APEX10', string $partnerName = 'Apex Media Ltd'): array
    {
        $partner = Partner::create([
            'name' => $partnerName,
            'contact_person' => $partnerName.' Contact',
            'email' => str($code)->lower().'@example.com',
            'phone' => '98765'.substr((string) crc32($code), 0, 5),
            'status' => Partner::STATUS_APPROVED,
        ]);

        $referralCode = ReferralCode::create([
            'partner_id' => $partner->id,
            'code' => $code,
            'status' => 'active',
            'start_date' => '2026-01-01',
            'expiry_date' => '2027-01-01',
            'usage_limit' => 100,
            'used_count' => 0,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'commission_type' => 'percentage',
            'commission_value' => 5,
        ]);

        return [$partner, $referralCode];
    }

    /**
     * Persist a referral usage row (drives referral + partner reporting).
     *
     * @return int the new referral_usages id
     */
    protected function makeReferralUsage(
        Partner $partner,
        ReferralCode $code,
        float $discount,
        float $commission,
        string $createdAt
    ): int {
        return (int) DB::table('referral_usages')->insertGetId([
            'referral_code_id' => $code->id,
            'partner_id' => $partner->id,
            'booking_id' => null,
            'user_id' => $this->advertiser->id,
            'discount_amount' => $discount,
            'commission_amount' => $commission,
            'status' => 'completed',
            'used_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * Create a role granting the given permissions.
     *
     * Authorization is role driven (Role::grantedPermissions() behind
     * PermissionMiddleware), so permission tests must seed a role rather than
     * attach permissions to the user. The middleware memoises permissions per
     * role slug, so the cache is flushed afterwards.
     *
     * @param  array<int, string>  $permissions
     */
    protected function makeRoleWithPermissions(string $slug, array $permissions): Role
    {
        $role = Role::create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => 'Seeded for reporting access tests',
        ]);

        foreach ($permissions as $permission) {
            RolePermission::create([
                'role_id' => $role->id,
                'permission' => $permission,
                'granted' => true,
            ]);
        }

        PermissionMiddleware::clearPermissionCache($slug);

        return $role;
    }
}
