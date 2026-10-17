<?php

namespace Tests\Unit\Infrastructure\Auth;

use App\Infrastructure\Auth\GoogleOAuthAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleOAuthAdapterTest extends TestCase
{
    public function test_verify_id_token_successful_returns_user_data(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'sub' => '1234567890',
                'email' => 'test@example.com',
                'name' => 'Test User',
                'picture' => 'https://example.com/avatar.jpg',
            ], 200),
        ]);

        $adapter = new GoogleOAuthAdapter();
        $userData = $adapter->verifyIdToken('valid_google_token');

        $this->assertIsArray($userData);
        $this->assertEquals('test@example.com', $userData['email']);
        $this->assertEquals('1234567890', $userData['sub']);
        $this->assertEquals('Test User', $userData['name']);
    }

    public function test_verify_id_token_failed_returns_null(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'error_description' => 'Invalid Value',
            ], 400),
        ]);

        $adapter = new GoogleOAuthAdapter();
        $userData = $adapter->verifyIdToken('invalid_token');

        $this->assertNull($userData);
    }
}
