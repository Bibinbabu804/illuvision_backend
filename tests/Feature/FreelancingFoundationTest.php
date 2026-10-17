<?php

namespace Tests\Feature;

use App\Models\FreelancerService;
use App\Models\FreelancerProfile;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FreelancingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_freelancer_profile_and_skills(): void
    {
        $user = User::factory()->create(['role' => 'freelancer']);

        $this->actingAs($user)->putJson('/api/freelancing/me', [
            'professional_title' => 'Laravel API Engineer',
            'bio' => 'Builds reliable APIs.',
            'hourly_rate' => 2500,
            'languages' => ['English', 'Malayalam'],
        ])->assertOk()->assertJsonPath('data.professional_title', 'Laravel API Engineer');

        $this->actingAs($user)->putJson('/api/freelancing/me/skills', [
            'skills' => ['Laravel', 'PHP', 'Laravel'],
        ])->assertOk()->assertJsonCount(2, 'data.skills');

        $this->assertDatabaseHas('freelancer_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseHas('freelancer_skills', ['name' => 'Laravel']);
    }

    public function test_freelancer_can_create_service_with_packages_and_public_clients_can_discover_it(): void
    {
        $user = User::factory()->create(['role' => 'freelancer']);

        $response = $this->actingAs($user)->postJson('/api/freelancing/me/services', [
            'title' => 'I will build a Laravel REST API',
            'description' => 'A secure API implementation.',
            'category' => 'Development',
            'skills' => ['Laravel', 'PHP'],
            'status' => 'active',
            'packages' => [
                ['name' => 'Basic', 'price' => 10000, 'delivery_days' => 5, 'revisions' => 1, 'features' => ['Auth']],
                ['name' => 'Premium', 'price' => 25000, 'delivery_days' => 10, 'revisions' => 3, 'features' => ['Auth', 'Tests']],
            ],
        ])->assertCreated()->assertJsonPath('data.packages.0.name', 'Basic');

        $service = FreelancerService::firstOrFail();
        $this->getJson('/api/freelancing/services?search=Laravel')
            ->assertOk()
            ->assertJsonPath('data.0.id', $service->id);
    }

    public function test_freelancer_can_create_portfolio_and_upload_validated_media(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'freelancer']);

        $itemResponse = $this->actingAs($user)->postJson('/api/freelancing/me/portfolio', [
            'title' => 'Marketplace API project',
            'description' => 'A portfolio project.',
            'skills' => ['Laravel', 'MySQL'],
        ])->assertCreated();

        $item = PortfolioItem::firstOrFail();
        $this->actingAs($user)
            ->post('/api/freelancing/me/portfolio/'.$item->id.'/media', [
                'file' => UploadedFile::fake()->image('portfolio.png'),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('portfolio_media', [
            'portfolio_item_id' => $item->id,
            'media_type' => 'image',
        ]);
        $this->assertNotNull($itemResponse->json('data.id'));
    }

    public function test_freelancer_cannot_modify_another_freelancers_service(): void
    {
        $owner = User::factory()->create(['role' => 'freelancer']);
        $other = User::factory()->create(['role' => 'freelancer']);
        $service = $this->actingAs($owner)->postJson('/api/freelancing/me/services', [
            'title' => 'Owned service',
            'description' => 'Owned by another user.',
            'packages' => [['name' => 'Basic', 'price' => 100, 'delivery_days' => 1]],
        ])->assertCreated()->json('data.id');

        $this->actingAs($other)->putJson('/api/freelancing/me/services/'.$service, [
            'title' => 'Tampered service',
        ])->assertForbidden();
    }

    public function test_public_profile_hides_private_work_and_private_contact_data(): void
    {
        $user = User::factory()->create(['role' => 'freelancer', 'email' => 'private@example.test']);
        $profile = FreelancerProfile::create(['user_id' => $user->id, 'professional_title' => 'Designer']);

        $profile->services()->create([
            'title' => 'Published service', 'slug' => 'published-service', 'description' => 'Public work', 'status' => 'active',
        ])->packages()->create(['name' => 'Basic', 'price' => 100, 'delivery_days' => 2]);
        $profile->services()->create([
            'title' => 'Draft service', 'slug' => 'draft-service', 'description' => 'Private work', 'status' => 'draft',
        ]);
        $profile->portfolioItems()->create(['title' => 'Public case study', 'visibility' => 'public', 'status' => 'published']);
        $profile->portfolioItems()->create(['title' => 'Private case study', 'visibility' => 'private', 'status' => 'draft']);

        $response = $this->getJson('/api/freelancing/freelancers/'.$profile->id)->assertOk();
        $response->assertJsonMissingPath('data.user.email');
        $response->assertJsonCount(1, 'data.services');
        $response->assertJsonCount(1, 'data.portfolio');
        $response->assertJsonPath('data.services.0.title', 'Published service');
        $response->assertJsonPath('data.portfolio.0.title', 'Public case study');
    }

    public function test_only_clients_can_create_projects(): void
    {
        $freelancer = User::factory()->create(['role' => 'freelancer']);
        $serviceId = $this->actingAs($freelancer)->postJson('/api/freelancing/me/services', [
            'title' => 'Role-protected service', 'description' => 'A service', 'status' => 'active',
            'packages' => [['name' => 'Basic', 'price' => 100, 'delivery_days' => 1]],
        ])->assertCreated()->json('data.id');
        $packageId = FreelancerService::findOrFail($serviceId)->packages()->firstOrFail()->id;

        $this->actingAs($freelancer)->postJson('/api/freelancing/projects', [
            'service_id' => $serviceId, 'package_id' => $packageId, 'title' => 'Should be rejected',
        ])->assertForbidden();
    }
}
