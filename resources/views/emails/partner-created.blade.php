@component('emails.layout', [
    'headerTitle' => 'New Partner Registered',
    'headerColor' => '#6366f1',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">A new partner has registered on the platform.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Partner Name</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $partner->name }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Contact Person</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $partner->contact_person ?? 'N/A' }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Email</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $partner->email }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Phone</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $partner->phone ?? 'N/A' }}</td></tr>
    </table>
@endcomponent
