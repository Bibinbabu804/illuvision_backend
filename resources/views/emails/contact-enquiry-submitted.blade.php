@component('emails.layout', [
    'headerTitle' => 'New Contact Enquiry',
    'headerColor' => '#6366f1',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">A new contact enquiry has been submitted.</p>
    
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Name</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $enquiry->name }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Email</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $enquiry->email }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Phone</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $enquiry->phone }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; vertical-align: top;">Message</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1; white-space: pre-wrap;">{{ $enquiry->message }}</td></tr>
    </table>

    <div style="text-align: center; margin: 32px 0 16px;">
        <a href="{{ config('app.admin_url') }}/contact-enquiries/{{ $enquiry->id }}" style="display: inline-block; background-color: #13ff01; color: #000000; font-family: 'Inter', -apple-system, sans-serif; font-weight: 700; font-size: 13px; text-decoration: none; padding: 12px 32px; border-radius: 4px; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 12px rgba(19, 255, 1, 0.2);">View Enquiry</a>
    </div>
@endcomponent
