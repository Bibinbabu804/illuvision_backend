@component('emails.layout', [
    'headerTitle' => 'Application Update',
    'headerColor' => '#ef4444',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Dear {{ $partner->name }},</p>

    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 16px;">Thank you for your interest in becoming a partner with Illuvision.</p>

    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 16px;">After careful review, we regret to inform you that your partner application has been <strong>rejected</strong> at this time.</p>

    @if($reason)
    <div style="background: #1c1515; border-left: 4px solid #ef4444; padding: 16px; border-radius: 6px; margin: 20px 0; color: #fca5a5; font-size: 14px;">
        <strong>Reason for Decision:</strong><br>
        <span style="color: #cbd5e1; margin-top: 4px; display: block;">{{ $reason }}</span>
    </div>
    @endif

    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 24px;">We encourage you to reapply in the future if your circumstances change. If you have any questions about this decision, please contact our support team.</p>

    <p style="font-size: 14px; color: #cbd5e1; margin: 24px 0 0;">Best regards,<br>The Illuvision Team</p>
@endcomponent
