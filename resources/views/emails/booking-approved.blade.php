@component('emails.layout', [
    'headerTitle' => 'Booking Approved ✓',
    'headerColor' => '#10b981',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Your campaign has been approved and is scheduled to run.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Campaign</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $booking->campaign_title ?? $booking->title }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Start Time</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $booking->start_time?->format('M d, Y h:i A') }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">End Time</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $booking->end_time?->format('M d, Y h:i A') }}</td></tr>
    </table>
@endcomponent
