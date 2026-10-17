@component('emails.layout', [
    'headerTitle' => 'Booking Cancelled',
    'headerColor' => '#ef4444',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">A booking has been cancelled.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Campaign</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $booking->campaign_title ?? $booking->title }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Advertiser</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $booking->advertiser_name }}</td></tr>
    </table>
@endcomponent
