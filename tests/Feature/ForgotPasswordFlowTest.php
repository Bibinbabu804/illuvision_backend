<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ForgotPasswordFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_queues_email_for_existing_active_user(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'testuser@example.com',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'testuser@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);

        Mail::assertQueued(PasswordResetMail::class, function ($mail) use ($user) {
            return $mail->hasTo('testuser@example.com') && $mail->user->id === $user->id;
        });

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'testuser@example.com',
        ]);
    }

    public function test_forgot_password_for_non_existent_email_returns_success_without_queuing_mail(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);

        Mail::assertNothingQueued();
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'resetme@example.com',
            'password' => Hash::make('oldpassword123'),
            'status' => 'active',
        ]);

        $rawToken = 'sampletoken1234567890abcdefghijklmnopqrstuvwxyz';
        DB::table('password_reset_tokens')->insert([
            'email' => 'resetme@example.com',
            'token' => hash('sha256', $rawToken),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => 'resetme@example.com',
            'token' => $rawToken,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));

        // Token must be deleted after use
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'resetme@example.com',
        ]);
    }

    public function test_reset_password_fails_with_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'resetme2@example.com',
            'status' => 'active',
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => 'resetme2@example.com',
            'token' => hash('sha256', 'correcttoken'),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => 'resetme2@example.com',
            'token' => 'wrongtoken',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }
}
