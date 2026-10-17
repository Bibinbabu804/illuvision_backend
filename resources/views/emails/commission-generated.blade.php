@component('emails.layout', [
    'headerTitle' => 'Commission Earned!',
    'headerColor' => '#10b981',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">A new commission has been credited to your account.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Commission Amount</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">₹{{ number_format($commission->amount, 2) }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Status</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #13ff01; text-transform: capitalize;">{{ $commission->status }}</td></tr>
    </table>
@endcomponent
