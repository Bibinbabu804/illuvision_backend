@component('emails.layout', [
    'headerTitle' => 'Refund Processed',
    'headerColor' => '#ef4444',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">A refund has been processed for your booking.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Refund Amount</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">₹{{ number_format($payment->amount, 2) }}</td></tr>
        @if($reason)
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Reason</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $reason }}</td></tr>
        @endif
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Campaign</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $payment->booking?->campaign_title ?? $payment->booking?->title ?? 'N/A' }}</td></tr>
    </table>
    <p style="font-size: 13px; color: #94a3b8; margin-top: 16px;">Refunds typically appear within 5-7 business days.</p>
@endcomponent
