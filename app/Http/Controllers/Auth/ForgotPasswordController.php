<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Infrastructure\Notifications\Contracts\MailContractInterface;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    public function __construct(
        protected MailContractInterface $mail,
    ) {}

    /**
     * Send a password reset link to the user's email.
     */
    public function __invoke(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        // Return generic success message for security if user does not exist or is not active
        if (! $user || $user->status !== 'active') {
            return $this->successResponse(null, 'If the account exists, a password reset link has been sent.');
        }

        // Delete any existing tokens for this email
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        // Create a new reset token
        $token = Str::random(64);
        $hashedToken = hash('sha256', $token);

        DB::table('password_reset_tokens')->insert([
            'email' => $request->email,
            'token' => $hashedToken,
            'created_at' => Carbon::now(),
        ]);

        // Send password reset email
        $frontendUrl = rtrim(config('app.frontend_url') ?: 'https://illuvision.net', '/');
        $resetLink = "{$frontendUrl}/auth/reset-password?token={$token}&email=".urlencode($request->email);

        $this->mail->queue($request->email, null, new PasswordResetMail($user, $resetLink));

        return $this->successResponse(null, 'If the account exists, a password reset link has been sent.');
    }
}
