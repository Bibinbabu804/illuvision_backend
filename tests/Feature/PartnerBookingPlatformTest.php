<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerBookingPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected User $partnerUser;
    protected Partner $partner;
    protected ReferralCode $referralCode;
    protected User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create approved partner user & partner profile
        $this->partnerUser = User::create([
            'name' => 'Ad Partner Alpha',
            'email' => 'partner@agency.com',
            'password' => bcrypt('password123'),
            'role' => 'partner',
            'status' => 'active',
        ]);

        $this->partner = Partner::create([
            'user_id' => $this->partnerUser->id,
            'name' => 'Agency Alpha Ltd',
            'contact_person' => 'Jane Partner',
            'email' => 'partner@agency.com',
            'phone' => '+919876543210',
            'status' => Partner::STATUS_APPROVED,
        ]);

        // 2. Create active referral code
        $this->referralCode = ReferralCode::create([
            'partner_id' => $this->partner->id,
            'code' => 'ALPHA10',
            'status' => 'active',
            'discount_type' => 'percentage',
            'discount_value' => 10.00,
            'commission_type' => 'percentage',
            'commission_value' => 5.00,
            'start_date' => now(),
            'expiry_date' => now()->addYear(),
        ]);

        // 3. Create managed client profile under partner
        $this->clientUser = User::create([
            'name' => 'Client Bravo',
            'email' => 'bravo@brand.com',
            'phone' => '+919998887770',
            'password' => bcrypt('password123'),
            'role' => 'user',
            'created_by_partner_id' => $this->partner->id,
        ]);
    }

    public function test_partner_client_isolation_and_ownership()
    {
        $this->actingAs($this->partnerUser);

        // Partner sees own client
        $response = $this->getJson('/api/partner/clients');
        $response->assertStatus(200);
        $response->assertJsonFragment(['email' => 'bravo@brand.com']);

        // Create another partner & unowned client
        $otherPartnerUser = User::create([
            'name' => 'Other Partner',
            'email' => 'other@agency.com',
            'password' => bcrypt('password123'),
            'role' => 'partner',
            'status' => 'active',
        ]);
        $otherPartner = Partner::create([
            'user_id' => $otherPartnerUser->id,
            'name' => 'Agency Beta',
            'contact_person' => 'Other Person',
            'email' => 'other@agency.com',
            'phone' => '+919876543211',
            'status' => Partner::STATUS_APPROVED,
        ]);

        $unownedClient = User::create([
            'name' => 'Unowned Client',
            'email' => 'unowned@client.com',
            'password' => bcrypt('password123'),
            'role' => 'user',
            'created_by_partner_id' => $otherPartner->id,
        ]);

        // Attempting to access unowned client returns 404
        $showResponse = $this->getJson("/api/partner/clients/{$unownedClient->id}");
        $showResponse->assertStatus(404);
    }

    public function test_direct_payment_requires_mandatory_admin_verification()
    {
        $admin = User::create([
            'name' => 'System Admin',
            'email' => 'admin@illuvision.net',
            'password' => bcrypt('password123'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        // Create booking with direct bank payment
        $booking = Booking::create([
            'user_id' => $this->clientUser->id,
            'created_by_partner_id' => $this->partner->id,
            'referral_code_id' => $this->referralCode->id,
            'title' => 'Direct Payment Campaign',
            'campaign_title' => 'Direct Payment Campaign',
            'advertiser_name' => $this->clientUser->name,
            'start_time' => now()->addDays(2),
            'end_time' => now()->addDays(5),
            'ad_frequency' => 'every_5_min',
            'price' => 1000.00,
            'subtotal' => 1000.00,
            'final_amount' => 1000.00,
            'total_screens' => 1,
            'status' => Booking::STATUS_PENDING_APPROVAL,
            'source' => 'partner',
            'booking_source' => 'partner_portal',
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => 1000.00,
            'currency' => 'INR',
            'payment_status' => Payment::PAYMENT_STATUS_AWAITING_VERIFICATION,
            'payment_method' => 'direct_bank',
            'payment_reference' => 'UTR987654321',
        ]);

        // Admin checks verification queue
        $this->actingAs($admin);
        $queueResponse = $this->getJson('/api/admin/payments/direct-verification-queue');
        $queueResponse->assertStatus(200);
        $queueResponse->assertJsonFragment(['payment_reference' => 'UTR987654321']);

        // Admin approves direct payment
        $approveResponse = $this->postJson("/api/admin/payments/{$payment->id}/approve-direct");
        $approveResponse->assertStatus(200);

        // Verify payment is completed and referral commission generated
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'payment_status' => Payment::PAYMENT_STATUS_COMPLETED,
            'verified_by_admin_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('partner_commissions', [
            'partner_id' => $this->partner->id,
            'booking_id' => $booking->id,
            'status' => 'pending',
        ]);
    }
}
