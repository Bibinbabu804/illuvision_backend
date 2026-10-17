<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\Booking;
use App\Models\City;
use App\Models\Country;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\State;
use App\Models\User;
use App\Services\CampaignPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BP5 — Booking pricing integrity & availability hardening.
 *
 * Covers Sprint BP5 §25–27 / §68 for the existing booking flow
 * (POST /bookings/campaign):
 *   - The booking amount must come from the authoritative
 *     CampaignPricingService result for the exact screen_ids + quantities +
 *     dates — never from client-supplied subtotal/final_amount.
 *   - Mobile fleet quantities must be validated against date-range
 *     availability (InventoryService) before a booking can be created, so a
 *     stale recommendation or concurrent booking cannot create an invalid
 *     over-capacity booking.
 */
class CampaignBookingPricingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Country $country;
    private State $kerala;
    private City $kochi;
    private ScreenCategory $mall;
    private PricingPlan $planA; // ₹500/day, min 1 day
    private Screen $fixedKochiMall;
    private Screen $mobileFleet; // fleet_size 100

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        AdvertisementCategory::create([
            'name' => 'General',
            'slug' => 'general',
            'status' => 'active',
        ]);

        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $this->kerala = State::create(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->kochi = City::create(['name' => 'Kochi', 'state_id' => $this->kerala->id, 'country_id' => $this->country->id]);

        $this->mall = ScreenCategory::create(['name' => 'Mall', 'slug' => 'mall', 'status' => 'active']);

        $this->planA = PricingPlan::create([
            'name' => 'Standard Daily', 'base_price' => 500, 'currency' => 'INR',
            'minimum_duration' => 1, 'minimum_duration_type' => 'Day',
            'is_default' => true, 'is_active' => true,
        ]);

        $this->fixedKochiMall = Screen::create([
            'name' => 'Kochi Mall Screen',
            'screen_code' => 'FIX-BP5-001',
            'asset_type' => 'fixed',
            'screen_type' => 'LED Billboard',
            'screen_category_id' => $this->mall->id,
            'country_id' => $this->country->id,
            'city_id' => $this->kochi->id,
            'location_name' => $this->kochi->name,
            'pricing_plan_id' => $this->planA->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->mobileFleet = Screen::create([
            'name' => 'Kerala Mobile Fleet',
            'screen_code' => 'MOB-BP5-001',
            'asset_type' => 'mobile',
            'screen_type' => 'Digital Van',
            'screen_category_id' => $this->mall->id,
            'country_id' => $this->country->id,
            'location_name' => 'Base Depot',
            'pricing_plan_id' => $this->planA->id,
            'fleet_size' => 100,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    private function campaignPayload(array $overrides = []): array
    {
        return array_merge([
            'campaign_title' => 'BP5 Integrity Campaign',
            'ad_frequency' => 'every_5_min',
            'start_time' => '2026-11-01 00:00:00',
            'end_time' => '2026-11-10 23:59:59',
            'screen_ids' => [$this->mobileFleet->id],
            'quantities' => [$this->mobileFleet->id => 20],
            'total_screens' => 1,
            // Client-claimed amounts (deliberately wrong — quantity-1 pricing).
            'subtotal' => 5000,
            'discount_amount' => 0,
            'referral_discount' => 0,
            'final_amount' => 5000,
            'redeem_points' => 0,
            'advertisement_category_id' => AdvertisementCategory::where('slug', 'general')->value('id'),
        ], $overrides);
    }

    /**
     * Mirror the exact context storeCampaign passes to the oracle — the
     * authenticated user (first-booking discount eligibility) included — and
     * return the aggregated amounts used for the stored booking fields.
     */
    private function oracleAggregated(int $screenId, int $quantity, string $from, string $to): array
    {
        $oracle = app(CampaignPricingService::class)->calculateCampaignPreview([
            'screen_ids' => [$screenId],
            'quantities' => [$screenId => $quantity],
            'start_date' => $from,
            'end_date' => $to,
            'daily_start_time' => '00:00',
            'daily_end_time' => '23:59',
            'user' => $this->user,
        ]);

        return $oracle['aggregated'];
    }

    private function oracleFinal(int $screenId, int $quantity, string $from, string $to): float
    {
        return (float) $this->oracleAggregated($screenId, $quantity, $from, $to)['final_total'];
    }

    // ─── §25/§26 — authoritative pricing at booking time ────────────────

    public function test_booking_amount_uses_authoritative_server_pricing_not_client_amount(): void
    {
        $response = $this->postJson('/api/bookings/campaign', $this->campaignPayload());
        $response->assertStatus(201);

        $booking = Booking::latest('id')->first();
        $this->assertNotNull($booking);

        $expected = $this->oracleFinal($this->mobileFleet->id, 20, '2026-11-01', '2026-11-10');
        $this->assertGreaterThan(5000, $expected, '20 units × ₹500 × 10 days must exceed the client-claimed amount.');
        $this->assertEquals(
            $expected,
            (float) $booking->final_amount,
            'Stored final_amount must equal the authoritative CampaignPricingService result.'
        );
        $this->assertEquals(
            (float) $this->oracleAggregated($this->mobileFleet->id, 20, '2026-11-01', '2026-11-10')['subtotal_before_discount'],
            (float) $booking->price,
            'Stored price must equal the authoritative pre-discount subtotal.'
        );

        // The booking_screen line item must carry the real quantity and be
        // priced from the authoritative subtotal (not the client-claimed one).
        $lineItem = DB::table('booking_screens')->where('booking_id', $booking->id)->first();
        $this->assertEquals(20, (int) $lineItem->quantity);
        $authoritativeSubtotal = (float) $this->oracleAggregated(
            $this->mobileFleet->id, 20, '2026-11-01', '2026-11-10'
        )['subtotal_before_discount'];
        $this->assertEquals(
            round($authoritativeSubtotal, 2),
            round((float) $lineItem->line_subtotal, 2),
            'Line item subtotal must be derived from the authoritative subtotal.'
        );
    }

    public function test_booking_amount_is_authoritative_for_fixed_screen_too(): void
    {
        $payload = $this->campaignPayload([
            'screen_ids' => [$this->fixedKochiMall->id],
            'quantities' => [$this->fixedKochiMall->id => 1],
            'total_screens' => 1,
            'subtotal' => 1,
            'final_amount' => 1,
        ]);

        $response = $this->postJson('/api/bookings/campaign', $payload);
        $response->assertStatus(201);

        $expected = $this->oracleFinal($this->fixedKochiMall->id, 1, '2026-11-01', '2026-11-10');
        $booking = Booking::latest('id')->first();
        $this->assertEquals($expected, (float) $booking->final_amount);
        $this->assertNotEquals(1, (float) $booking->final_amount);
    }

    // ─── §27/§68 — mobile fleet capacity validated at booking time ─────

    public function test_booking_rejects_quantity_over_fleet_availability(): void
    {
        // Reserve 90 of 100 fleet units over the window → 10 available.
        $bookingId = DB::table('bookings')->insertGetId([
            'screen_id' => null,
            'title' => 'Fixture booking',
            'advertiser_name' => 'Test Advertiser',
            'start_time' => '2026-11-01 00:00:00',
            'end_time' => '2026-11-30 23:59:59',
            'price' => 0,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('booking_screens')->insert([
            'booking_id' => $bookingId,
            'screen_id' => $this->mobileFleet->id,
            'quantity' => 90,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 20 requested > 10 available → must be rejected.
        $response = $this->postJson('/api/bookings/campaign', $this->campaignPayload());
        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase(
            'no longer available',
            $response->json('message')
        );

        $this->assertEquals(1, Booking::count(), 'No booking may be created for unavailable capacity.');
    }

    public function test_booking_accepts_quantity_within_fleet_availability(): void
    {
        // No reservations → full fleet available → quantity 20 is valid.
        $response = $this->postJson('/api/bookings/campaign', $this->campaignPayload());
        $response->assertStatus(201);

        $booking = Booking::latest('id')->first();
        $this->assertNotNull($booking);
        $this->assertEquals(20, (int) DB::table('booking_screens')->where('booking_id', $booking->id)->value('quantity'));
    }
}
