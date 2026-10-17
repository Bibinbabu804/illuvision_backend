@component('emails.layout', [
    'headerTitle' => 'Welcome to ILLUVISION!',
    'headerColor' => '#13ff01',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Thank you for joining Illuvision. Your account has been created successfully.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px; width: 30%;">Name</td><td style="padding: 10px 0; font-size: 14px; font-weight: 600; color: #ffffff;">{{ $user->name }}</td></tr>
        <tr style="border-bottom: 1px solid #1f1f1f;"><td style="padding: 10px 0; color: #94a3b8; font-size: 14px;">Email</td><td style="padding: 10px 0; font-size: 14px; color: #cbd5e1;">{{ $user->email }}</td></tr>
    </table>
    <p style="font-size: 13px; color: #94a3b8; margin-top: 16px;">You can now browse available screens and create your first campaign.</p>
@endcomponent
