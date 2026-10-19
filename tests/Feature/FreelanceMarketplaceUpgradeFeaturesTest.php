<?php

namespace Tests\Feature;

use App\Models\FreelanceCategory;
use App\Models\FreelanceCategoryRequest;
use App\Models\FreelanceProject;
use App\Models\FreelanceProjectMilestone;
use App\Models\FreelanceReview;
use App\Models\FreelancerProfile;
use App\Models\FreelancerService;
use App\Models\FreelancerWallet;
use App\Models\User;
use App\Services\Freelance\ContentSafetyService;
use App\Services\Freelance\ResponseTimeCalculatorService;
use App\Services\Freelance\ServicePricingEstimatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FreelanceMarketplaceUpgradeFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function createFreelancer(): User
    {
        $user = User::factory()->create(['role' => 'freelancer']);
        FreelancerProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['professional_title' => 'Senior Developer', 'hourly_rate' => 1500]
        );
        return $user->fresh('freelancerProfile');
    }

    public function test_feature_1_milestone_based_payment_and_partial_release(): void
    {
        $freelancer = $this->createFreelancer();
        $client = User::factory()->create(['role' => 'client']);

        $service = FreelancerService::create([
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'title' => 'Web App Development',
            'slug' => 'web-app-dev',
            'description' => 'Full stack development',
            'status' => 'active',
            'moderation_status' => 'approved',
        ]);
        $package = $service->packages()->create([
            'name' => 'Standard',
            'price' => 10000.00,
            'delivery_days' => 10,
            'revisions' => 2,
        ]);

        // Client orders service
        $resp = $this->actingAs($client)->postJson('/api/freelancing/projects', [
            'service_id' => $service->id,
            'package_id' => $package->id,
            'title' => 'E-Commerce Platform',
        ]);
        $resp->assertCreated();
        $projectId = $resp->json('data.id');

        $project = FreelanceProject::with('milestones')->findOrFail($projectId);
        $this->assertCount(2, $project->milestones);
        $m1 = $project->milestones->firstWhere('milestone_number', 1);
        $m2 = $project->milestones->firstWhere('milestone_number', 2);

        $this->assertEquals(30, $m1->percentage);
        $this->assertEquals(70, $m2->percentage);

        // Simulate client payment held
        $project->update([
            'status' => 'in_progress',
            'held_amount' => $project->gross_amount,
        ]);

        // Freelancer submits Milestone 1 deliverable
        $submitResp = $this->actingAs($freelancer)->postJson("/api/freelancing/projects/{$project->id}/milestones/{$m1->id}/deliverables", [
            'title' => 'Initial Prototype & Database Schema',
            'message' => 'First milestone ready for review',
        ]);
        $submitResp->assertCreated();
        $this->assertEquals('submitted', $m1->fresh()->status);

        // Client approves Milestone 1
        $approveResp = $this->actingAs($client)->postJson("/api/freelancing/projects/{$project->id}/milestones/{$m1->id}/approve");
        $approveResp->assertOk();

        // Verify Milestone 1 is approved and advance funds released to wallet
        $m1Fresh = $m1->fresh();
        $this->assertEquals('approved', $m1Fresh->status);
        $this->assertNotNull($m1Fresh->released_at);

        $wallet = FreelancerWallet::where('freelancer_profile_id', $freelancer->freelancerProfile->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals($m1Fresh->freelancer_net_amount, (float) $wallet->available_balance);

        // Project should remain in_progress for Milestone 2
        $this->assertEquals('in_progress', $project->fresh()->status);
    }

    public function test_feature_2_and_5_category_requests_and_admin_management(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $freelancer = User::factory()->create(['role' => 'freelancer']);

        // Freelancer requests a new service category
        $reqResp = $this->actingAs($freelancer)->postJson('/api/freelancing/me/category-requests', [
            'name' => 'Artificial Intelligence & Machine Learning',
            'description' => 'AI agent building and model fine-tuning',
            'type' => 'service',
        ]);
        $reqResp->assertCreated();
        $requestId = $reqResp->json('data.id');

        // Admin approves category request
        $approveResp = $this->actingAs($admin)->postJson("/api/freelancing/admin/category-requests/{$requestId}/approve");
        $approveResp->assertOk();

        $this->assertDatabaseHas('freelance_categories', [
            'name' => 'Artificial Intelligence & Machine Learning',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('freelance_category_requests', [
            'id' => $requestId,
            'status' => 'approved',
        ]);
    }

    public function test_feature_3_service_pause_and_resume(): void
    {
        $freelancer = $this->createFreelancer();
        $client = User::factory()->create(['role' => 'client']);

        $service = FreelancerService::create([
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'title' => 'Logo & Brand Identity',
            'slug' => 'logo-brand-identity',
            'description' => 'Professional logos',
            'status' => 'active',
            'moderation_status' => 'approved',
            'is_paused' => false,
        ]);
        $package = $service->packages()->create([
            'name' => 'Basic',
            'price' => 2000,
            'delivery_days' => 3,
        ]);

        // Freelancer pauses service
        $pauseResp = $this->actingAs($freelancer)->postJson("/api/freelancing/me/services/{$service->id}/pause", [
            'reason' => 'Taking a short break',
        ]);
        $pauseResp->assertOk();
        $this->assertTrue((bool) $service->fresh()->is_paused);

        // Paused service is still discoverable in public list
        $listResp = $this->getJson('/api/freelancing/services');
        $listResp->assertOk();

        // But client cannot order a paused service
        $orderResp = $this->actingAs($client)->postJson('/api/freelancing/projects', [
            'service_id' => $service->id,
            'package_id' => $package->id,
            'title' => 'Order paused service',
        ]);
        $orderResp->assertStatus(422);

        // Resume service
        $resumeResp = $this->actingAs($freelancer)->postJson("/api/freelancing/me/services/{$service->id}/resume");
        $resumeResp->assertOk();
        $this->assertFalse((bool) $service->fresh()->is_paused);
    }

    public function test_feature_4_service_video_upload_and_moderation(): void
    {
        $freelancer = $this->createFreelancer();
        $service = FreelancerService::create([
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'title' => 'Video Editing Services',
            'slug' => 'video-editing',
            'description' => '4K editing',
            'status' => 'active',
            'moderation_status' => 'approved',
        ]);

        $videoFile = UploadedFile::fake()->create('promo.mp4', 5000, 'video/mp4');
        $uploadResp = $this->actingAs($freelancer)->post("/api/freelancing/me/services/{$service->id}/video", [
            'video' => $videoFile,
        ]);
        $uploadResp->assertOk();

        $fresh = $service->fresh();
        $this->assertNotNull($fresh->video_path);
        $this->assertEquals('pending', $fresh->video_status);
        $this->assertEquals('pending_review', $fresh->moderation_status);
    }

    public function test_feature_7_controlled_platform_communication_detection(): void
    {
        $service = app(ContentSafetyService::class);

        // Phone number detection
        $check1 = $service->scanText('Call me directly on +91 98765 43210 for discounts');
        $this->assertTrue($check1['has_violations']);
        $this->assertContains('phone_number', $check1['violations']);

        // Email address detection
        $check2 = $service->scanText('Write to me at john.doe@gmail.com to pay offline');
        $this->assertTrue($check2['has_violations']);
        $this->assertContains('email_address', $check2['violations']);

        // WhatsApp / Telegram detection
        $check3 = $service->scanText('Ping me on telegram @john_freelance or whatsapp me');
        $this->assertTrue($check3['has_violations']);
        $this->assertContains('external_chat_platform', $check3['violations']);

        // Chat endpoint rejection
        $freelancer = $this->createFreelancer();
        $client = User::factory()->create(['role' => 'client']);
        $srv = FreelancerService::create([
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'title' => 'Testing Safety',
            'slug' => 'testing-safety',
            'description' => 'Test',
            'status' => 'active',
            'moderation_status' => 'approved',
        ]);
        $pkg = $srv->packages()->create(['name' => 'B', 'price' => 500, 'delivery_days' => 1]);
        $prjResp = $this->actingAs($client)->postJson('/api/freelancing/projects', [
            'service_id' => $srv->id, 'package_id' => $pkg->id, 'title' => 'Test Safety',
        ]);
        $convId = FreelanceProject::findOrFail($prjResp->json('data.id'))->conversation->id;

        $msgResp = $this->actingAs($client)->postJson("/api/freelancing/conversations/{$convId}/messages", [
            'body' => 'Contact me at testuser@yahoo.com or call 9876543210',
        ]);
        $msgResp->assertStatus(422);
    }

    public function test_feature_8_admin_service_and_portfolio_approval(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $freelancer = $this->createFreelancer();

        $service = FreelancerService::create([
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'title' => 'Pending Service',
            'slug' => 'pending-service',
            'description' => 'Awaiting review',
            'status' => 'active',
            'moderation_status' => 'pending_review',
        ]);

        $modResp = $this->actingAs($admin)->postJson("/api/freelancing/admin/services/{$service->id}/moderate", [
            'decision' => 'approved',
            'feedback' => 'Approved by moderation team',
        ]);
        $modResp->assertOk();
        $this->assertEquals('approved', $service->fresh()->moderation_status);
    }

    public function test_feature_9_freelancer_unavailable_date_range(): void
    {
        $freelancer = $this->createFreelancer();
        $client = User::factory()->create(['role' => 'client']);

        // Schedule unavailable dates from today to +5 days
        $from = now()->format('Y-m-d');
        $to = now()->addDays(5)->format('Y-m-d');

        $unavailResp = $this->actingAs($freelancer)->postJson('/api/freelancing/me/availability', [
            'from_date' => $from,
            'to_date' => $to,
            'reason' => 'Annual vacation',
        ]);
        $unavailResp->assertCreated();

        $service = FreelancerService::create([
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'title' => 'Graphic Design',
            'slug' => 'graphic-design',
            'description' => 'Graphics',
            'status' => 'active',
            'moderation_status' => 'approved',
        ]);
        $pkg = $service->packages()->create(['name' => 'B', 'price' => 1000, 'delivery_days' => 2]);

        // Attempt to order while unavailable
        $orderResp = $this->actingAs($client)->postJson('/api/freelancing/projects', [
            'service_id' => $service->id,
            'package_id' => $pkg->id,
            'title' => 'Project during vacation',
        ]);
        $orderResp->assertStatus(422);
        $this->assertStringContainsString('unavailable', strtolower($orderResp->json('message')));
    }

    public function test_feature_10_response_time_formatting(): void
    {
        $this->assertEquals('Response time not available yet.', ResponseTimeCalculatorService::format(null));
        $this->assertEquals('Usually responds within 1 hour', ResponseTimeCalculatorService::format(1800));
        $this->assertEquals('Usually responds within 4 hours', ResponseTimeCalculatorService::format(7200));
        $this->assertEquals('Usually responds within 1 day', ResponseTimeCalculatorService::format(40000));
    }

    public function test_feature_11_service_price_estimator(): void
    {
        $estimator = app(ServicePricingEstimatorService::class);
        $freelancer = $this->createFreelancer();
        $service = FreelancerService::create([
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'title' => 'Custom Software',
            'slug' => 'custom-software',
            'description' => 'Software engineering',
            'status' => 'active',
            'moderation_status' => 'approved',
        ]);
        $pkg = $service->packages()->create([
            'name' => 'Basic',
            'price' => 2000.00,
            'delivery_days' => 5,
        ]);

        $estimate = $estimator->estimate($service, [
            'package_id' => $pkg->id,
            'quantity' => 2,
            'extra_revisions' => 2,
            'express_delivery' => true,
            'custom_addons' => [
                ['name' => 'SEO Optimization', 'price' => 1000],
            ],
        ]);

        // Base: 4000 + 600 + 500 + 1000 = 6100
        $this->assertEquals(6100.00, $estimate['subtotal']);
        $this->assertGreaterThan(6100.00, $estimate['total_amount']);
        $this->assertArrayHasKey('platform_fee', $estimate['breakdown']);
        $this->assertArrayHasKey('applicable_tax', $estimate['breakdown']);
    }

    public function test_feature_13_review_reply_and_deliverable_comments(): void
    {
        $freelancer = $this->createFreelancer();
        $client = User::factory()->create(['role' => 'client']);

        $service = FreelancerService::create([
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'title' => 'Review Reply Service',
            'slug' => 'review-reply-service',
            'description' => 'Service for reviews',
            'status' => 'active',
            'moderation_status' => 'approved',
        ]);
        $pkg = $service->packages()->create(['name' => 'B', 'price' => 500, 'delivery_days' => 1]);

        $prj = FreelanceProject::create([
            'client_id' => $client->id,
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'freelancer_service_id' => $service->id,
            'freelancer_service_package_id' => $pkg->id,
            'reference' => 'PRJ-REV1',
            'title' => 'Project for review',
            'status' => 'completed',
            'gross_amount' => 500,
            'platform_fee' => 50,
            'freelancer_amount' => 450,
            'delivery_days' => 1,
            'revision_limit' => 1,
        ]);

        $review = FreelanceReview::create([
            'project_id' => $prj->id,
            'client_id' => $client->id,
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'rating' => 5,
            'review' => 'Outstanding work delivered on time!',
        ]);

        // Freelancer replies to review
        $replyResp = $this->actingAs($freelancer)->postJson("/api/freelancing/reviews/{$review->id}/reply", [
            'reply' => 'Thank you for your fantastic feedback! Looking forward to working together again.',
        ]);
        $replyResp->assertOk();

        $this->assertDatabaseHas('freelance_review_replies', [
            'review_id' => $review->id,
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
        ]);
    }

    public function test_feature_14_profile_media_gallery(): void
    {
        $freelancer = $this->createFreelancer();
        $img = UploadedFile::fake()->image('profile1.jpg', 600, 600);

        $uploadResp = $this->actingAs($freelancer)->post('/api/freelancing/me/profile-media', [
            'file' => $img,
        ]);
        $uploadResp->assertCreated();

        $this->assertDatabaseHas('freelancer_profile_media', [
            'freelancer_profile_id' => $freelancer->freelancerProfile->id,
            'is_primary' => true,
        ]);
    }
}
