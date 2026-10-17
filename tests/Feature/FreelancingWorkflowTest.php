<?php

namespace Tests\Feature;

use App\Models\FreelanceProject;
use App\Models\FreelancerService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FreelancingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_request_service_and_freelancer_can_send_and_client_can_accept_proposal(): void
    {
        $freelancer = User::factory()->create(['role' => 'freelancer']);
        $client = User::factory()->create(['role' => 'client']);
        $serviceId = $this->actingAs($freelancer)->postJson('/api/freelancing/me/services', [
            'title' => 'Custom web build',
            'description' => 'A custom build.',
            'status' => 'active',
            'packages' => [['name' => 'Basic', 'price' => 1000, 'delivery_days' => 5, 'revisions' => 1]],
        ])->assertCreated()->json('data.id');
        $packageId = FreelancerService::findOrFail($serviceId)->packages()->firstOrFail()->id;

        $projectId = $this->actingAs($client)->postJson('/api/freelancing/projects', [
            'service_id' => $serviceId,
            'package_id' => $packageId,
            'title' => 'Build my website',
        ])->assertCreated()->json('data.id');

        $proposalId = $this->actingAs($freelancer)->postJson('/api/freelancing/projects/'.$projectId.'/proposals', [
            'amount' => 1200,
            'delivery_days' => 7,
            'revisions' => 2,
            'description' => 'I can deliver this.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($client)->postJson('/api/freelancing/proposals/'.$proposalId.'/accept')
            ->assertOk()->assertJsonPath('data.status', 'payment_pending');

        $this->assertDatabaseHas('freelance_projects', ['id' => $projectId, 'status' => 'payment_pending']);
    }

    public function test_only_conversation_participants_can_read_and_send_messages(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'freelancer']);
        $client = User::factory()->create(['role' => 'client']);
        $outsider = User::factory()->create(['role' => 'client']);
        $serviceId = $this->actingAs($owner)->postJson('/api/freelancing/me/services', [
            'title' => 'Chat service', 'description' => 'Chat service', 'status' => 'active',
            'packages' => [['name' => 'Basic', 'price' => 100, 'delivery_days' => 1]],
        ])->json('data.id');
        $service = FreelancerService::findOrFail($serviceId);
        $projectId = $this->actingAs($client)->postJson('/api/freelancing/projects', ['service_id' => $service->id, 'package_id' => $service->packages()->first()->id, 'title' => 'Chat'])->json('data.id');
        $conversationId = FreelanceProject::findOrFail($projectId)->conversation->id;

        $this->actingAs($outsider)->getJson('/api/freelancing/conversations/'.$conversationId.'/messages')->assertForbidden();
        $this->actingAs($client)->postJson('/api/freelancing/conversations/'.$conversationId.'/messages', ['body' => 'Hello'])->assertCreated();
        $this->actingAs($client)->post('/api/freelancing/projects/'.$projectId.'/files', [
            'file' => UploadedFile::fake()->create('requirements.pdf', 20, 'application/pdf'),
        ])->assertCreated();
        $this->assertDatabaseHas('freelance_project_files', ['project_id' => $projectId, 'purpose' => 'requirement']);
    }
}
