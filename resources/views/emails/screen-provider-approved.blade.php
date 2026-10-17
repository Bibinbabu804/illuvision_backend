@component('emails.layout', [
    'headerTitle' => 'Welcome to the Illuvision Network!',
    'headerColor' => '#6366f1',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Dear {{ $screenProvider->provider_name }},</p>
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 16px;">Congratulations! Your application to join the Illuvision Network has been <strong>approved</strong>. You are now officially a Screen Provider with Illuvision.</p>

    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 24px;">We are excited to have you on board. As a Screen Provider, you can list your screens on our platform and start earning revenue from digital advertising campaigns.</p>

    <div style="background: #18181b; border: 1px solid #1f1f1f; padding: 16px; border-radius: 6px; margin: 24px 0;">
        <p style="font-size: 13px; color: #94a3b8; margin: 0 0 4px;">Business Name:</p>
        <p style="font-size: 14px; color: #ffffff; margin: 0 0 12px; font-weight: 600;">{{ $screenProvider->business_name ?: $screenProvider->provider_name }}</p>
        <p style="font-size: 13px; color: #94a3b8; margin: 0 0 4px;">Contact Email:</p>
        <p style="font-size: 14px; color: #13ff01; margin: 0; font-family: monospace;">{{ $screenProvider->email }}</p>
    </div>

    <p style="font-size: 14px; color: #cbd5e1; margin: 24px 0 0;">Best regards,<br>The Illuvision Team</p>
@endcomponent
