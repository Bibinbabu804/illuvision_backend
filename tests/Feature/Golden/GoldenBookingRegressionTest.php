<?php

namespace Tests\Feature\Golden;

use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\Screen;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoldenBookingRegressionTest extends TestCase
{
    use RefreshDatabase, GoldenTestSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupGoldenEnvironment();
        Event::fake();
    }

    /**
     * Channel 1: Advertiser Booking Channel (POST /api/bookings/campaign)
     */
    public function test_advertiser_channel_booking_creation_and_snapshots(): void
    {
        Sanctum::actingAs($this->customerUser);

        $startDate = Carbon::now()->addDays(2)->format('Y-m-d');
        $endDate = Carbon::now()->addDays(4)->format('Y-m-d');

        $payload = [
            'campaign_title' => 'Advertiser Campaign 2026',
            'ad_frequency' => 'every_5_min',
            'start_time' => $startDate . ' 09:00:00',
            'end_time' => $endDate . ' 21:00:00',
            'daily_start_time' => '09:00',
            'daily_end_time' => '21:00',
            'screen_ids' => [$this->fixedScreenProvider->id],
            'total_screens' => 1,
            'subtotal' => 3000.00,
            'final_amount' => 3000.00,
            'advertisement_category_id' => $this->adCategory->id,
        ];

        $response = $this->postJson('/api/bookings/campaign', $payload);

        $response->assertStatus(201);
        $bookingId = $response->json('data.id');

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'user_id' => $this->customerUser->id,
            'status' => Booking::STATUS_PAYMENT_PENDING,
            'source' => 'website',
        ]);

        // Verify BookingScreen snapshot
        $bookingScreen = BookingScreen::where('booking_id', $bookingId)->first();
        $this->assertNotNull($bookingScreen);
        $this->assertEquals($this->fixedScreenProvider->id, $bookingScreen->screen_id);
        $this->assertEquals(Screen::OWNER_TYPE_PROVIDER, $bookingScreen->owner_type);
        $this->assertEquals(80.00, $bookingScreen->screen_provider_percentage);
        $this->assertEquals(20.00, $bookingScreen->illuvision_percentage);
        $this->assertNotNull($bookingScreen->pricing_snapshot);
    }

    /**
     * Channel 2: Partner Booking Channel (POST /api/partner/bookings)
     */
    public function test_partner_channel_booking_creation(): void
    {
        Sanctum::actingAs($this->partnerUser);

        $startDate = Carbon::now()->addDays(5)->format('Y-m-d');
        $endDate = Carbon::now()->addDays(7)->format('Y-m-d');

        $payload = [
            'client' => [
                'name' => 'Partner Client Corp',
                'email' => 'client@partnercorp.com',
                'phone' => '9898989898',
            ],
            'campaign_title' => 'Partner Managed Campaign',
            'ad_frequency' => 'every_5_min',
            'start_time' => $startDate . ' 09:00:00',
            'end_time' => $endDate . ' 21:00:00',
            'daily_start_time' => '09:00',
            'daily_end_time' => '21:00',
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'total_screens' => 1,
            'subtotal' => 3000.00,
            'final_amount' => 2700.00, // 10% partner referral discount
            'advertisement_category_id' => $this->adCategory->id,
        ];

        $response = $this->postJson('/api/partner/bookings', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'booking' => [
                        'id',
                        'title',
                        'status',
                    ],
                ],
            ]);

        $bookingId = $response->json('data.booking.id');
        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'created_by_partner_id' => $this->partner->id,
            'referral_code_id' => $this->referralCode->id,
        ]);
    }

    /**
     * Channel 3: Admin Manual Booking Channel (POST /api/admin/manual-bookings)
     */
    public function test_admin_manual_booking_creation(): void
    {
        Sanctum::actingAs($this->adminUser);

        $startDate = Carbon::now()->addDays(8)->format('Y-m-d');
        $endDate = Carbon::now()->addDays(10)->format('Y-m-d');

        $payload = [
            'client' => [
                'name' => 'Direct VIP Client',
                'email' => 'vip@directclient.com',
                'phone' => '9123456789',
            ],
            'booking_source' => 'admin_manual',
            'campaign_title' => 'VIP Admin Campaign',
            'ad_frequency' => 'every_2_min',
            'start_time' => $startDate . ' 09:00:00',
            'end_time' => $endDate . ' 21:00:00',
            'daily_start_time' => '09:00',
            'daily_end_time' => '21:00',
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'total_screens' => 1,
            'subtotal' => 3000.00,
            'final_amount' => 3000.00,
            'advertisement_category_id' => $this->adCategory->id,
            'payment_mode' => 'cash',
        ];

        $response = $this->postJson('/api/admin/manual-bookings', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('bookings', [
            'campaign_title' => 'VIP Admin Campaign',
            'advertiser_name' => 'Direct VIP Client',
            'booking_source' => 'admin_manual',
        ]);
    }

    /**
     * Channel 4: V2 API Booking Channel (POST /api/v2/bookings)
     *
     * SEC-02 security-contract change: this route was fully anonymous and is now
     * authenticated. The test now acts as the customer, exactly like the other three
     * channels in this file. Every golden assertion below (status, JSON structure,
     * booking_reference format) is UNCHANGED - only the caller identity changed.
     */
    public function test_v2_api_booking_creation(): void
    {
        Sanctum::actingAs($this->customerUser);

        $startDate = Carbon::now()->addDays(12)->format('Y-m-d');
        $endDate = Carbon::now()->addDays(14)->format('Y-m-d');

        $payload = [
            'campaign_title' => 'V2 API Automated Campaign',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'items' => [
                [
                    'asset_id' => $this->fixedScreenIlluvision->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/v2/bookings', $payload);

        $this->assertContains($response->status(), [200, 201]);
        $response->assertJsonStructure([
            'message',
            'data' => [
                'id',
                'booking_reference',
                'status',
            ],
        ]);

        $this->assertStringStartsWith('ILL-', $response->json('data.booking_reference'));
    }
}
