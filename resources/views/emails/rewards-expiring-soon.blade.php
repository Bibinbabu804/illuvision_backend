@component('emails.layout', [
    'headerTitle' => 'Your Ad Credits Are Expiring',
    'headerColor' => '#ef4444',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Heads up! <strong style="color: #f87171;">{{ number_format($expiringPoints) }} Ad Credits</strong> in your account will expire on <strong style="color: #f87171;">{{ $expiryDate }}</strong>.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Credits Expiring</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #f87171;">{{ number_format($expiringPoints) }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Expiry Date</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $expiryDate }}</td></tr>
    </table>
    <p style="font-size: 13px; color: #94a3b8; margin: 16px 0 0;">Use them before they expire! Book a campaign now and redeem your credits as a discount at checkout.</p>
@endcomponent
