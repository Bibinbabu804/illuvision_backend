<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ScreenProvider;
use App\Models\ScreenProviderEnquiry;
use App\Services\ScreenProviderService;
use App\Support\Events\Notifications\ScreenProviderApproved;
use App\Support\Events\Notifications\ScreenProviderPortalInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScreenProviderInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected ScreenProviderService $service;
    protected User $admin;
    protected int $initialCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ScreenProviderService::class);
        
        $this->initialCount = User::count();

        // Create an admin user for testing
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@illuvision.net',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin);
    }

    public function test_approval_from_enquiry_creates_user_generates_token_and_dispatches_events()
    {
        Event::fake();

        // 1. Create an enquiry
        $enquiry = ScreenProviderEnquiry::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890',
            'message' => 'Hello, I want to host screens.',
            'status' => 'new',
        ]);

        // 2. Approve the enquiry
        $provider = $this->service->approveFromEnquiry($enquiry, $this->admin);

        // 3. Verify user record created
        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('screen_provider', $user->role);
        $this->assertEquals('active', $user->status);

        // 4. Verify user_id saved on screen provider
        $this->assertEquals($user->id, $provider->user_id);
        $this->assertEquals('approved', $provider->status);

        // 5. Verify password setup token exists
        $tokenRecord = DB::table('password_setup_tokens')->where('email', 'john@example.com')->first();
        $this->assertNotNull($tokenRecord);
        $this->assertFalse((bool) $tokenRecord->used);
        $this->assertTrue(now()->lt($tokenRecord->expires_at));

        // 6. Verify correct events are dispatched
        Event::assertDispatched(ScreenProviderApproved::class, function ($event) use ($provider) {
            return $event->screenProvider->id === $provider->id;
        });

        Event::assertDispatched(ScreenProviderPortalInvitation::class, function ($event) use ($provider, $tokenRecord) {
            return $event->screenProvider->id === $provider->id && $event->setupToken === $tokenRecord->token;
        });
    }

    public function test_linking_existing_user_during_approval()
    {
        Event::fake();

        // 1. Create an existing user with the same email (e.g. a customer)
        $existingUser = User::create([
            'name' => 'Existing Customer',
            'email' => 'john@example.com',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'status' => 'active',
        ]);

        $enquiry = ScreenProviderEnquiry::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '1234567890',
            'message' => 'Want to host screens.',
            'status' => 'new',
        ]);

        // 2. Approve and link existing user
        $provider = $this->service->approveFromEnquiry($enquiry, $this->admin, true);

        // 3. Verify user was linked and role was updated (no new user created)
        $this->assertEquals($this->initialCount + 2, User::count()); // admin + john
        $existingUser->refresh();
        $this->assertEquals('screen_provider', $existingUser->role);
        $this->assertEquals($existingUser->id, $provider->user_id);
    }

    public function test_manual_portal_invitation_sends_invitation_without_duplicate_user()
    {
        Event::fake();

        // 1. Create a screen provider manually (associated with a user already)
        $user = User::create([
            'name' => 'Provider User',
            'email' => 'provider@example.com',
            'password' => bcrypt('password'),
            'role' => 'screen_provider',
            'status' => 'active',
        ]);

        $provider = ScreenProvider::create([
            'provider_name' => 'Test Provider',
            'contact_person' => 'Test Person',
            'phone' => '1234567890',
            'email' => 'provider@example.com',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);

        // 2. Send portal invitation
        $result = $this->service->sendPortalInvitation($provider, $this->admin);

        // 3. Verify no new user was created
        $this->assertEquals($this->initialCount + 2, User::count()); // admin + provider
        $this->assertEquals($user->id, $result['screen_provider']->user_id);

        // 4. Verify password setup token exists
        $tokenRecord = DB::table('password_setup_tokens')->where('email', 'provider@example.com')->first();
        $this->assertNotNull($tokenRecord);

        // 5. Verify ONLY PortalInvitation event was dispatched (not Approved)
        Event::assertNotDispatched(ScreenProviderApproved::class);
        Event::assertDispatched(ScreenProviderPortalInvitation::class, function ($event) use ($provider, $tokenRecord) {
            return $event->screenProvider->id === $provider->id && $event->setupToken === $tokenRecord->token;
        });
    }

    public function test_resending_portal_invitation_invalidates_previous_unused_token()
    {
        Event::fake();

        $user = User::create([
            'name' => 'Provider User',
            'email' => 'provider@example.com',
            'password' => bcrypt('password'),
            'role' => 'screen_provider',
            'status' => 'active',
        ]);

        $provider = ScreenProvider::create([
            'provider_name' => 'Test Provider',
            'contact_person' => 'Test Person',
            'phone' => '1234567890',
            'email' => 'provider@example.com',
            'status' => 'approved',
            'user_id' => $user->id,
        ]);

        // 1. Generate first invitation
        $this->service->sendPortalInvitation($provider, $this->admin);
        
        $firstToken = DB::table('password_setup_tokens')->where('email', 'provider@example.com')->first();
        $this->assertNotNull($firstToken);
        $this->assertFalse((bool) $firstToken->used);

        // 2. Generate second invitation (resend)
        $this->service->sendPortalInvitation($provider, $this->admin);

        // 3. Verify first token is marked as used (invalidated) and a new active one is created
        $firstTokenRefreshed = DB::table('password_setup_tokens')->where('id', $firstToken->id)->first();
        $this->assertTrue((bool) $firstTokenRefreshed->used);

        $tokensCount = DB::table('password_setup_tokens')->where('email', 'provider@example.com')->count();
        $this->assertEquals(2, $tokensCount);

        $activeToken = DB::table('password_setup_tokens')
            ->where('email', 'provider@example.com')
            ->where('used', false)
            ->first();
        $this->assertNotNull($activeToken);
        $this->assertNotEquals($firstToken->token, $activeToken->token);
    }
}
