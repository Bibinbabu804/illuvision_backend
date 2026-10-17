@component('emails.layout', [
    'headerTitle' => 'Ad Credits Earned!',
    'headerColor' => '#8b5cf6',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Congratulations! You earned <strong style="color: #a78bfa;">{{ number_format($points) }} Ad Credits</strong> on your completed booking.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Campaign</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $booking->campaign_title ?? $booking->title }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Booking #</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $booking->id }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Ad Credits Earned</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #a78bfa;">+{{ number_format($points) }}</td></tr>
    </table>
    <p style="font-size: 13px; color: #94a3b8; margin: 16px 0 0;">Use your Ad Credits on future bookings to get exclusive discounts. Log in to your account to view your credit balance.</p>
@endcomponent
