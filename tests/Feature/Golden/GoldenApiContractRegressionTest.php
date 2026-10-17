<?php

namespace Tests\Feature\Golden;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoldenApiContractRegressionTest extends TestCase
{
    use RefreshDatabase, GoldenTestSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupGoldenEnvironment();
        Event::fake();
    }

    /**
     * Layer 1 — POST /api/campaigns/calculate contract
     */
    public function test_campaign_pricing_calculate_contract_success_and_validation(): void
    {
        $payload = [
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'start_date' => Carbon::now()->addDay()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(7)->format('Y-m-d'),
            'daily_start_time' => '09:00',
            'daily_end_time' => '21:00',
        ];

        $response = $this->postJson('/api/campaigns/calculate', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'campaign_summary' => [
                        'start_date',
                        'end_date',
                        'total_days',
                        'daily_runtime' => [
                            'start_time',
                            'end_time',
                        ],
                        'total_screens',
                    ],
                    'per_screen' => [
                        '*' => [
                            'screen_id',
                            'quantity',
                            'pricing_plan_id',
                            'screen_total' => [
                                'base_price',
                                'subtotal_before_discount',
                                'subtotal_after_discount',
                            ],
                        ],
                    ],
                    'aggregated' => [
                        'base_price',
                        'screen_count',
                        'combined_discount',
                        'subtotal',
                        'final_amount',
                    ],
                ],
            ]);

        // Validation Failure Contract (Standard Laravel 422 envelope)
        $invalidResponse = $this->postJson('/api/campaigns/calculate', []);
        $invalidResponse->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors' => ['screen_ids', 'start_date', 'end_date'],
            ]);
    }

    /**
     * Layer 1 — POST /api/v2/pricing/calculate contract
     */
    public function test_v2_pricing_calculate_contract_success_and_validation(): void
    {
        $payload = [
            'start_date' => Carbon::now()->addDay()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(5)->format('Y-m-d'),
            'items' => [
                [
                    'asset_id' => $this->fixedScreenIlluvision->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/v2/pricing/calculate', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'start_date',
                        'end_date',
                        'total_days',
                        'total_items',
                    ],
                    'line_items' => [
                        '*' => [
                            'asset_id',
                            'asset_name',
                            'quantity',
                            'unit_price_per_day',
                            'days',
                            'line_subtotal',
                        ],
                    ],
                    'pricing' => [
                        'gross_total',
                        'duration_discount',
                        'final_total',
                    ],
                ],
            ]);

        // Validation failure
        $invalidResponse = $this->postJson('/api/v2/pricing/calculate', []);
        $invalidResponse->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    /**
     * Layer 1 — POST /api/v2/availability/check contract
     */
    public function test_v2_availability_check_contract(): void
    {
        $payload = [
            'start_date' => Carbon::now()->addDay()->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(3)->format('Y-m-d'),
            'items' => [
                [
                    'asset_id' => $this->fixedScreenIlluvision->id,
                    'quantity' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/v2/availability/check', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'available',
                'eligible',
                'message',
                'conflicts',
                'screens',
            ]);
    }

    /**
     * Layer 1 — POST /api/bookings/campaign (Advertiser Channel) contract & auth
     */
    public function test_advertiser_campaign_booking_contract_and_auth(): void
    {
        $startDate = Carbon::now()->addDays(2)->format('Y-m-d');
        $endDate = Carbon::now()->addDays(4)->format('Y-m-d');

        $bookingPayload = [
            'campaign_title' => 'Golden Test Campaign 2026',
            'ad_frequency' => 'every_5_min',
            'start_time' => $startDate . ' 09:00:00',
            'end_time' => $endDate . ' 21:00:00',
            'daily_start_time' => '09:00',
            'daily_end_time' => '21:00',
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'total_screens' => 1,
            'subtotal' => 3000.00,
            'final_amount' => 3000.00,
            'advertisement_category_id' => $this->adCategory->id,
        ];

        // 1. Unauthenticated request must return 401
        $unauthResponse = $this->postJson('/api/bookings/campaign', $bookingPayload);
        $unauthResponse->assertStatus(401);

        // 2. Authenticated request must succeed with 201 and structured response
        Sanctum::actingAs($this->customerUser);
        $response = $this->postJson('/api/bookings/campaign', $bookingPayload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'id',
                    'campaign_title',
                    'status',
                    'subtotal',
                    'final_amount',
                    'booking_screens',
                ],
            ]);
    }

    /**
     * Layer 1 — POST /api/partner/bookings/calculate contract & auth
     */
    public function test_partner_calculate_contract_and_auth(): void
    {
        $payload = [
            'screen_ids' => [$this->fixedScreenIlluvision->id],
            'start_date' => Carbon::now()->addDays(3)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(5)->format('Y-m-d'),
        ];

        // Unauthenticated -> 401
        $this->postJson('/api/partner/bookings/calculate', $payload)->assertStatus(401);

        // Non-partner user -> 403
        Sanctum::actingAs($this->customerUser);
        $this->postJson('/api/partner/bookings/calculate', $payload)->assertStatus(403);

        // Partner user -> 200
        Sanctum::actingAs($this->partnerUser);
        $response = $this->postJson('/api/partner/bookings/calculate', $payload);
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'campaign_summary',
                    'per_screen',
                    'aggregated',
                ],
            ]);
    }

    /**
     * Layer 1 — POST /api/payments/razorpay/create-order contract & authorization
     */
    public function test_payment_order_create_contract_and_auth(): void
    {
        $booking = Booking::create([
            'user_id' => $this->customerUser->id,
            'campaign_title' => 'Payment Test Campaign',
            'title' => 'Payment Test Campaign',
            'advertiser_name' => $this->customerUser->name,
            'advertiser_email' => $this->customerUser->email,
            'start_time' => Carbon::now()->addDays(2),
            'end_time' => Carbon::now()->addDays(4),
            'subtotal' => 2000.00,
            'final_amount' => 2000.00,
            'price' => 2000.00,
            'status' => Booking::STATUS_PAYMENT_PENDING,
        ]);

        // Unauthenticated -> 401
        $this->postJson('/api/payments/razorpay/create-order', ['booking_id' => $booking->id])
            ->assertStatus(401);

        // Another user's booking -> 403 Unauthorized
        $otherUser = User::factory()->create(['role' => 'user']);
        Sanctum::actingAs($otherUser);
        $this->postJson('/api/payments/razorpay/create-order', ['booking_id' => $booking->id])
            ->assertStatus(403);

        // Owner request -> fresh order (201)
        Sanctum::actingAs($this->customerUser);
        $response = $this->postJson('/api/payments/razorpay/create-order', ['booking_id' => $booking->id]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'payment',
                    'razorpay_order_id',
                    'amount',
                    'currency',
                ],
            ]);

        $this->assertEquals(200000, $response->json('data.amount'));
        $this->assertEquals('INR', $response->json('data.currency'));
        $this->assertStringStartsWith('order_', $response->json('data.razorpay_order_id'));
    }

    /**
     * Layer 1 — GET /api/bookings/{id}/payment-status contract
     */
    public function test_payment_status_endpoint_contract(): void
    {
        $booking = Booking::create([
            'user_id' => $this->customerUser->id,
            'campaign_title' => 'Status Check Campaign',
            'title' => 'Status Check Campaign',
            'advertiser_name' => $this->customerUser->name,
            'advertiser_email' => $this->customerUser->email,
            'start_time' => Carbon::now()->addDays(2),
            'end_time' => Carbon::now()->addDays(4),
            'final_amount' => 1500.00,
            'price' => 1500.00,
            'status' => Booking::STATUS_APPROVED,
        ]);

        Sanctum::actingAs($this->customerUser);
        $response = $this->getJson("/api/bookings/{$booking->id}/payment-status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'booking_id',
                    'booking_status',
                    'payment',
                ],
            ]);
    }

    /**
     * Layer 1 — POST /api/auth/google external integration contract
     */
    public function test_google_auth_contract_with_mocked_tokeninfo(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'sub' => 'google_uid_998811',
                'email' => 'google.user@example.com',
                'name' => 'Google Test User',
                'picture' => 'https://lh3.googleusercontent.com/a/test',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/google', [
            'credential' => 'fake_google_jwt_token',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'user' => ['id', 'email', 'name'],
                    'token',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'google.user@example.com',
            'google_id' => 'google_uid_998811',
        ]);
    }
}
