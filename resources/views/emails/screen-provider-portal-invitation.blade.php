@component('emails.layout', [
    'headerTitle' => 'Illuvision Screen Provider Portal',
    'headerColor' => '#00f0ff',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Hello {{ $screenProvider->provider_name }},</p>
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 16px;">You have been invited to access the Illuvision Screen Provider Portal.</p>
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 24px;">Through this portal, you can securely monitor your digital screens, track advertising campaigns allocated to them, view revenue splits, download payout statements, and submit support enquiries.</p>

    <div style="text-align: center; margin: 32px 0;">
        <a href="{{ $setupUrl }}" style="display: inline-block; background-color: #00f0ff; color: #000000; font-family: 'Inter', -apple-system, sans-serif; font-weight: 700; font-size: 13px; text-decoration: none; padding: 12px 32px; border-radius: 4px; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 12px rgba(0, 240, 255, 0.2);">Set Up Your Password</a>
    </div>

    <div style="background: #18181b; border: 1px solid #1f1f1f; padding: 16px; border-radius: 6px; margin: 24px 0;">
        <p style="font-size: 13px; color: #94a3b8; margin: 0 0 4px;">Registered Email:</p>
        <p style="font-size: 14px; color: #ffffff; margin: 0 0 12px; font-weight: 600;">{{ $screenProvider->email }}</p>
        <p style="font-size: 13px; color: #94a3b8; margin: 0 0 4px;">Token Expiry:</p>
        <p style="font-size: 13px; color: #ef4444; margin: 0; font-weight: 600;">This link is valid for 7 days.</p>
    </div>

    <p style="font-size: 13px; color: #94a3b8; margin: 0 0 24px;">Once your password is set, you can log in at: <a href="{{ config('app.frontend_url', 'https://illuvision.net') }}/?login=true" style="color: #00f0ff; text-decoration: underline;">{{ config('app.frontend_url', 'https://illuvision.net') }}</a></p>

    <p style="font-size: 14px; color: #cbd5e1; margin: 24px 0 0;">If you have any questions or require assistance, please contact us at: <a href="mailto:support@illuvision.net" style="color: #00f0ff; text-decoration: none;">support@illuvision.net</a></p>

    <p style="font-size: 14px; color: #cbd5e1; margin: 24px 0 0;">Best regards,<br>The Illuvision Team</p>

    <div style="border-top: 1px solid #1f1f1f; padding-top: 16px; margin-top: 24px;">
        <p style="font-size: 12px; color: #64748b; margin: 0;">If you are having trouble with the button above, copy and paste this URL into your browser:</p>
        <p style="font-size: 12px; color: #00f0ff; word-break: break-all; margin: 4px 0 0;"><a href="{{ $setupUrl }}" style="color: #00f0ff; text-decoration: none;">{{ $setupUrl }}</a></p>
    </div>
@endcomponent
