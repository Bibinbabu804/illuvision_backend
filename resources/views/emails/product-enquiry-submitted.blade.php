@component('emails.layout', [
    'headerTitle' => 'New Product Enquiry',
    'headerColor' => '#6366f1',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Dear Admin,</p>
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 16px;">A new product enquiry has been submitted with the following details:</p>
    
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Product</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $enquiry->product->name }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Name</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $enquiry->name }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Email</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $enquiry->email }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Phone</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $enquiry->phone }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; vertical-align: top;">Message</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1; white-space: pre-wrap;">{{ $enquiry->message }}</td></tr>
    </table>
    
    <p style="font-size: 13px; color: #94a3b8; margin-top: 16px;">Please log in to the admin panel to review and manage this enquiry.</p>
@endcomponent
