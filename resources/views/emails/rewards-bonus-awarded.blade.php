@component('emails.layout', [
    'headerTitle' => 'Bonus Ad Credits Awarded!',
    'headerColor' => '#f59e0b',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Great news! You've been awarded <strong style="color: #fbbf24;">{{ number_format($points) }} bonus Ad Credits</strong>.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Credits Awarded</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #fbbf24;">+{{ number_format($points) }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Reason</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $reason }}</td></tr>
    </table>
    <p style="font-size: 13px; color: #94a3b8; margin: 16px 0 0;">These bonus credits have been added to your account and can be used as discounts on your next booking.</p>
@endcomponent
