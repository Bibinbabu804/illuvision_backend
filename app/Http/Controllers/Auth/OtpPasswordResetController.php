<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpPasswordResetController extends Controller
{
    /**
     * Send 6-digit OTP to freelancer's email
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $this->errorResponse('No account was found with this email address.', 404);
        }

        // Specifically check if user has 'freelancer' role
        if ($user->role !== 'freelancer') {
            return $this->errorResponse('This password reset option is exclusively for Freelancer accounts. Your account role is ' . ucfirst($user->role) . '.', 403);
        }

        // Generate 6-digit OTP
        $otp = (string) mt_rand(100000, 999999);

        // Store hashed OTP in password_reset_tokens table
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($otp),
            'created_at' => Carbon::now(),
        ]);

        // Send OTP via SMTP
        try {
            Mail::send([], [], function ($message) use ($user, $otp) {
                $message->to($user->email, $user->name)
                    ->subject("IlluVision - Password Reset OTP Code: {$otp}")
                    ->html($this->renderOtpEmailTemplate($user->name, $otp));
            });
        } catch (\Throwable $e) {
            Log::error('Failed to send OTP email: ' . $e->getMessage());
            return $this->errorResponse('Unable to send OTP email at this time: ' . $e->getMessage(), 500);
        }

        return $this->successResponse([
            'email' => $user->email,
            'expires_in_minutes' => 15,
            'dev_otp' => config('app.debug') ? $otp : null,
        ], 'A 6-digit verification code has been sent to ' . $user->email . '.');
    }

    /**
     * Verify the entered 6-digit OTP
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|min:6|max:6',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$record) {
            return $this->errorResponse('No active OTP found. Please request a new code.', 400);
        }

        // Expire after 15 minutes
        if (Carbon::parse($record->created_at)->diffInMinutes(Carbon::now()) > 15) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return $this->errorResponse('This OTP code has expired. Please request a new code.', 400);
        }

        if (!Hash::check($request->otp, $record->token)) {
            return $this->errorResponse('Invalid verification code. Please check your email and try again.', 422);
        }

        return $this->successResponse([
            'email' => $request->email,
            'verified' => true,
        ], 'Verification code confirmed. You can now choose a new password.');
    }

    /**
     * Reset password after confirming OTP
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string|min:6|max:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (!$record || Carbon::parse($record->created_at)->diffInMinutes(Carbon::now()) > 15 || !Hash::check($request->otp, $record->token)) {
            return $this->errorResponse('The verification session is invalid or has expired. Please request a new OTP.', 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || $user->role !== 'freelancer') {
            return $this->errorResponse('Freelancer account not found.', 404);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->password),
        ]);

        // Clear used OTP token
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return $this->successResponse([
            'email' => $user->email,
        ], 'Your password has been successfully reset. Please sign in with your new credentials.');
    }

    /**
     * Branded HTML Email Template
     */
    protected function renderOtpEmailTemplate(string $name, string $otp): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Your IlluVision Password</title>
</head>
<body style="margin: 0; padding: 30px 10px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f7f8fa; color: #111827;">
  <table role="presentation" style="max-width: 540px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; border: 1px solid #e5e7eb; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.06);" width="100%" cellpadding="0" cellspacing="0">
    <!-- Header -->
    <tr>
      <td style="background-color: #640014; padding: 28px 36px; text-align: left;">
        <h1 style="margin: 0; color: #ffffff; font-size: 20px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase;">
          ILLUVISION
        </h1>
        <p style="margin: 4px 0 0 0; color: rgba(255,255,255,0.8); font-size: 13px;">Freelancer Workspace Security</p>
      </td>
    </tr>
    <!-- Content -->
    <tr>
      <td style="padding: 36px 36px 28px 36px;">
        <h2 style="margin: 0 0 16px 0; color: #111827; font-size: 20px; font-weight: 600;">
          Password Reset Verification
        </h2>
        <p style="margin: 0 0 20px 0; font-size: 14.5px; line-height: 1.6; color: #4b5563;">
          Hello <strong>{$name}</strong>,<br><br>
          We received a request to reset the password for your IlluVision <strong>Freelancer</strong> account. Use the 6-digit verification code below to complete the reset:
        </p>

        <!-- OTP Display Box -->
        <div style="background-color: #faf5f5; border: 2px dashed #640014; border-radius: 6px; padding: 20px; text-align: center; margin: 28px 0;">
          <span style="font-family: 'Courier New', Courier, monospace; font-size: 34px; font-weight: 700; letter-spacing: 0.35em; color: #640014;">
            {$otp}
          </span>
          <p style="margin: 8px 0 0 0; font-size: 12px; color: #6b7280;">
            This code expires in <strong>15 minutes</strong>.
          </p>
        </div>

        <p style="margin: 0 0 20px 0; font-size: 13.5px; line-height: 1.5; color: #6b7280;">
          If you did not request this password reset, please ignore this email or contact support. Your password will remain unchanged.
        </p>
      </td>
    </tr>
    <!-- Footer -->
    <tr>
      <td style="background-color: #f9fafb; border-top: 1px solid #e5e7eb; padding: 20px 36px; font-size: 12px; color: #9ca3af; text-align: center;">
        &copy; 2026 IlluVision Marketplace. All rights reserved.
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
