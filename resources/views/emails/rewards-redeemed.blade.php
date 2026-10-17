@component('emails.layout', [
    'headerTitle' => 'Ad Credits Redeemed',
    'headerColor' => '#0ea5e9',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">You successfully redeemed <strong style="color: #38bdf8;">{{ number_format($points) }} Ad Credits</strong> as a discount on your booking.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Campaign</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $booking->campaign_title ?? $booking->title }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Booking #</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $booking->id }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Credits Used</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #38bdf8;">-{{ number_format($points) }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Discount Applied</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #22c55e;">₹{{ number_format($booking->reward_discount_amount, 2) }}</td></tr>
    </table>
    <p style="font-size: 13px; color: #94a3b8; margin: 16px 0 0;">Your remaining balance has been updated. Keep booking to earn more Ad Credits!</p>
@endcomponent
