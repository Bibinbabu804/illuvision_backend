<?php

namespace Tests\Feature;

use App\Models\FreelanceNotification;
use App\Models\FreelancerProfile;
use App\Models\FreelancerWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreelancingDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_real_workspace_metrics_and_notifications_are_private(): void
    {
        $freelancer = User::factory()->create(['role' => 'freelancer']);
        $outsider = User::factory()->create(['role' => 'freelancer']);
        $profile = FreelancerProfile::create(['user_id' => $freelancer->id, 'professional_title' => 'Developer', 'rating' => 4.5]);
        FreelancerWallet::create(['freelancer_profile_id' => $profile->id, 'available_balance' => 1250, 'pending_balance' => 300, 'lifetime_earnings' => 5000]);
        $notification = FreelanceNotification::create(['user_id' => $freelancer->id, 'type' => 'project', 'title' => 'New project', 'body' => 'A client contacted you.']);

        $this->actingAs($freelancer)->getJson('/api/freelancing/dashboard')
            ->assertOk()
            ->assertJsonPath('data.wallet.available_balance', '1250.00')
            ->assertJsonPath('data.metrics.active_projects', 0)
            ->assertJsonPath('data.notifications.0.id', $notification->id);

        $this->actingAs($outsider)->postJson('/api/freelancing/notifications/'.$notification->id.'/read')->assertForbidden();
        $this->actingAs($freelancer)->postJson('/api/freelancing/notifications/'.$notification->id.'/read')->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);

        // Test mark all notifications read
        $notif2 = FreelanceNotification::create(['user_id' => $freelancer->id, 'type' => 'message', 'title' => 'Unread message']);
        $this->actingAs($freelancer)->postJson('/api/freelancing/notifications/read-all')->assertOk();
        $this->assertNotNull($notif2->fresh()->read_at);

        // Test reviews endpoint
        $client = User::factory()->create(['role' => 'client']);
        $project = \App\Models\FreelanceProject::create([
            'client_id' => $client->id,
            'freelancer_profile_id' => $profile->id,
            'reference' => 'PRJ-REV1',
            'title' => 'Review Test Project',
            'status' => 'completed',
            'gross_amount' => 100,
            'platform_fee' => 10,
            'freelancer_amount' => 90,
            'delivery_days' => 2,
        ]);
        \App\Models\FreelanceReview::create([
            'project_id' => $project->id,
            'client_id' => $client->id,
            'freelancer_profile_id' => $profile->id,
            'rating' => 5,
            'review' => 'Exceptional delivery!',
        ]);

        $this->actingAs($freelancer)->getJson('/api/freelancing/reviews')
            ->assertOk()
            ->assertJsonPath('data.0.rating', 5)
            ->assertJsonPath('data.0.review', 'Exceptional delivery!')
            ->assertJsonPath('data.0.client.name', $client->name);
    }
}
