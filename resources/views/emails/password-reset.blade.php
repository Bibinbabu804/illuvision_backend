@component('emails.layout', [
    'headerTitle' => 'Password Reset',
    'headerColor' => '#13ff01',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Hello, {{ $userName }}!</p>
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 24px;">We received a request to reset the password for your Illuvision account. Click the button below to set a new password:</p>
    
    <div style="text-align: center; margin: 32px 0;">
        <a href="{{ $resetLink }}" style="display: inline-block; background-color: #13ff01; color: #000000; font-family: 'Inter', -apple-system, sans-serif; font-weight: 700; font-size: 13px; text-decoration: none; padding: 12px 32px; border-radius: 4px; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 12px rgba(19, 255, 1, 0.2);">Reset Password</a>
    </div>

    <p style="font-size: 14px; color: #cbd5e1; margin: 24px 0 12px;">This link will expire in <strong>60 minutes</strong>.</p>
    <p style="font-size: 14px; color: #94a3b8; margin: 0 0 24px;">If you did not request a password reset, please ignore this email.</p>
    
    <div style="border-top: 1px solid #1f1f1f; padding-top: 16px; margin-top: 24px;">
        <p style="font-size: 12px; color: #64748b; margin: 0;">If the button above doesn't work, copy and paste this URL into your browser:</p>
        <p style="font-size: 12px; color: #13ff01; word-break: break-all; margin: 4px 0 0;"><a href="{{ $resetLink }}" style="color: #13ff01; text-decoration: none;">{{ $resetLink }}</a></p>
    </div>
@endcomponent
