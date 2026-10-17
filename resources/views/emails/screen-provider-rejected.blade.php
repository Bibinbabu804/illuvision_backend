@component('emails.layout', [
    'headerTitle' => 'Application Status Update',
    'headerColor' => '#ef4444',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Dear {{ $screenProvider->provider_name }},</p>
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 16px;">Thank you for your interest in joining the Illuvision Network.</p>

    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 16px;">After careful review, we regret to inform you that your application has been <strong>declined</strong> at this time.</p>

    @if($reason)
    <div style="background: #18181b; border: 1px solid #1f1f1f; padding: 16px; border-radius: 6px; margin: 24px 0;">
        <p style="font-size: 13px; color: #94a3b8; margin: 0 0 8px; text-transform: uppercase; letter-spacing: 0.5px;">Reason</p>
        <p style="font-size: 14px; color: #fca5a5; margin: 0; line-height: 1.5;">{{ $reason }}</p>
    </div>
    @endif

    <p style="font-size: 14px; color: #cbd5e1; margin: 24px 0 0;">You are welcome to re-apply in the future if your circumstances change.</p>

    <p style="font-size: 14px; color: #cbd5e1; margin: 24px 0 0;">Best regards,<br>The Illuvision Team</p>
@endcomponent
