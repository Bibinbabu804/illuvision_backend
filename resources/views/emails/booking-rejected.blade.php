@component('emails.layout', [
    'headerTitle' => 'Booking Not Approved',
    'headerColor' => '#ef4444',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Unfortunately, your campaign booking could not be approved.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Campaign</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $booking->campaign_title ?? $booking->title }}</td></tr>
        @if($reason)
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; vertical-align: top;">Reason</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $reason }}</td></tr>
        @endif
    </table>
    <p style="font-size: 13px; color: #94a3b8; margin-top: 16px;">Please contact support if you have any questions.</p>
@endcomponent
