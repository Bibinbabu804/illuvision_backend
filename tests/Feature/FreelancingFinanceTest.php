<?php

namespace Tests\Feature;

use App\Models\FreelancePayment;
use App\Models\FreelanceProject;
use App\Models\FreelancerProfile;
use App\Models\FreelancerWallet;
use App\Models\FreelancerWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreelancingFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_webhook_is_verified_and_idempotent(): void
    {
        config(['services.razorpay.webhook_secret' => 'test-webhook-secret']);
        $client = User::factory()->create(['role' => 'client']);
        $freelancer = User::factory()->create(['role' => 'freelancer']);
        $profile = FreelancerProfile::create(['user_id' => $freelancer->id, 'professional_title' => 'Designer']);
        $project = FreelanceProject::create([
            'client_id' => $client->id, 'freelancer_profile_id' => $profile->id,
            'reference' => 'PRJ-WEBHOOK', 'title' => 'Webhook project', 'status' => 'payment_pending',
            'gross_amount' => 1000, 'platform_fee' => 100, 'freelancer_amount' => 900,
        ]);
        $payment = FreelancePayment::create([
            'project_id' => $project->id, 'client_id' => $client->id,
            'gateway_order_id' => 'order_test_1', 'amount' => 1000, 'status' => 'pending',
        ]);
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_test_1', 'order_id' => 'order_test_1', 'amount' => 100000]]]]);
        $signature = hash_hmac('sha256', $body, 'test-webhook-secret');

        $this->call('POST', '/api/freelancing/payments/webhook', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();
        $this->assertDatabaseHas('freelance_payments', ['id' => $payment->id, 'status' => 'held', 'gateway_payment_id' => 'pay_test_1']);
        $this->assertDatabaseHas('freelance_projects', ['id' => $project->id, 'status' => 'in_progress']);

        $this->call('POST', '/api/freelancing/payments/webhook', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertDatabaseCount('freelance_payments', 1);
    }

    public function test_super_admin_can_process_withdrawal(): void
    {
        $freelancer = User::factory()->create(['role' => 'freelancer']);
        $profile = FreelancerProfile::create(['user_id' => $freelancer->id]);
        FreelancerWallet::create(['freelancer_profile_id' => $profile->id, 'available_balance' => 500]);
        $withdrawal = FreelancerWithdrawal::create([
            'freelancer_profile_id' => $profile->id, 'amount' => 250,
            'payment_method' => 'bank_transfer', 'account_reference' => 'acct-test', 'requested_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'super-admin']))
            ->postJson('/api/freelancing/admin/withdrawals/'.$withdrawal->id.'/process', ['status' => 'completed', 'admin_note' => 'Paid'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_rejected_withdrawal_returns_reserved_balance(): void
    {
        $freelancer = User::factory()->create(['role' => 'freelancer']);
        $profile = FreelancerProfile::create(['user_id' => $freelancer->id]);
        FreelancerWallet::create(['freelancer_profile_id' => $profile->id, 'available_balance' => 500]);
        $withdrawal = FreelancerWithdrawal::create([
            'freelancer_profile_id' => $profile->id, 'amount' => 250,
            'payment_method' => 'bank_transfer', 'account_reference' => 'acct-test', 'requested_at' => now(),
        ]);
        $wallet = FreelancerWallet::where('freelancer_profile_id', $profile->id)->first();
        $wallet->decrement('available_balance', 250);

        $this->actingAs(User::factory()->create(['role' => 'super-admin']))
            ->postJson('/api/freelancing/admin/withdrawals/'.$withdrawal->id.'/process', ['status' => 'rejected'])
            ->assertOk();

        $this->assertDatabaseHas('freelancer_wallets', ['id' => $wallet->id, 'available_balance' => '500.00']);
    }
}
