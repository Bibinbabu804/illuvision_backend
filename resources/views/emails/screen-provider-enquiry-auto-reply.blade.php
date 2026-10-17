@component('emails.layout', [
    'headerTitle' => 'Thank You for Your Interest',
    'headerColor' => '#13ff01',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Dear {{ $enquiry->name }},</p>
    
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 16px;">Thank you for your interest in joining the Illuvision network. Our team will contact you shortly to discuss how we can help grow your display business.</p>
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 24px;">We look forward to partnering with you!</p>
    
    <p style="font-size: 14px; color: #cbd5e1; margin: 24px 0 0;">Best regards,<br>The Illuvision Team</p>
@endcomponent
