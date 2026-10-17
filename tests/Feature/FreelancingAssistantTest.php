<?php

namespace Tests\Feature;

use Tests\TestCase;

class FreelancingAssistantTest extends TestCase
{
    public function test_assistant_response_is_server_generated_and_does_not_require_frontend_provider_keys(): void
    {
        $this->postJson('/api/freelancing/assistant', ['message' => 'Is payment safe?'])
            ->assertOk()
            ->assertJsonPath('data.generated_by', 'illuvision-assistant')
            ->assertJsonPath('data.reply', 'Payments are verified server-side and held until the client approves the submitted work. The frontend cannot release funds by itself.');
    }
}
