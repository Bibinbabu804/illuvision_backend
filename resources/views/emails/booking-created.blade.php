@component('emails.layout', [
    'headerTitle' => 'New Booking Created',
    'headerColor' => '#6366f1',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">A new booking has been submitted.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Campaign</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $booking->campaign_title ?? $booking->title }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Advertiser</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $booking->advertiser_name }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Email</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $booking->advertiser_email }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Amount</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">₹{{ number_format($booking->final_amount ?? $booking->price, 2) }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Status</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #13ff01; text-transform: capitalize;">{{ str_replace('_', ' ', $booking->status) }}</td></tr>
    </table>
@endcomponent
