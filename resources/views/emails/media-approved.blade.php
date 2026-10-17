@component('emails.layout', [
    'headerTitle' => 'Media Approved ✓',
    'headerColor' => '#10b981',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Your uploaded media has been approved.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">File</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $media->original_name }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Campaign</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $booking->campaign_title ?? $booking->title }}</td></tr>
    </table>
@endcomponent
