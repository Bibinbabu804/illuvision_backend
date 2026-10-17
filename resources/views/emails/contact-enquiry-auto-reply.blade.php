@component('emails.layout', [
    'headerTitle' => 'Thank You for Contacting Us',
    'headerColor' => '#13ff01',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Dear {{ $enquiry->name }},</p>
    
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 16px;">Thank you for contacting us. We have received your message and will get back to you shortly.</p>
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 24px;">Our team typically responds within 24 hours. If your enquiry is urgent, please feel free to call us directly.</p>
    
    <p style="font-size: 14px; color: #ffffff; font-weight: 600; margin: 24px 0 12px;">Here is a summary of your message:</p>
    
    <div style="background: #18181b; border: 1px solid #1f1f1f; padding: 16px; border-radius: 6px; margin: 16px 0; color: #cbd5e1; font-size: 14px; white-space: pre-wrap;">{{ $enquiry->message }}</div>
    
    <p style="font-size: 14px; color: #cbd5e1; margin: 24px 0 0;">Best regards,<br>The Illuvision Team</p>
@endcomponent
