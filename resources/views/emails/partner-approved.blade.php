@component('emails.layout', [
    'headerTitle' => 'Welcome to the Partner Program!',
    'headerColor' => '#10b981',
])
    <p style="font-size: 15px; color: #e2e8f0; margin: 0 0 16px;">Dear {{ $partner->name }},</p>
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 16px;">Congratulations! Your partner application has been <strong>approved</strong>. You are now registered as an official Illuvision partner.</p>
    
    <p style="font-size: 14px; color: #cbd5e1; margin: 0 0 24px;">To get started and activate your partner portal, please set up your account password:</p>

    <div style="text-align: center; margin: 32px 0;">
        <a href="{{ $setupUrl }}" style="display: inline-block; background-color: #13ff01; color: #000000; font-family: 'Inter', -apple-system, sans-serif; font-weight: 700; font-size: 13px; text-decoration: none; padding: 12px 32px; border-radius: 4px; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 12px rgba(19, 255, 1, 0.2);">Set Up Your Password</a>
    </div>

    <p style="font-size: 14px; color: #ffffff; font-weight: 600; margin: 24px 0 12px;">As an Illuvision Partner, you can:</p>
    <ul style="font-size: 14px; color: #cbd5e1; margin: 0 0 24px; padding-left: 20px;">
        <li style="margin-bottom: 6px;">Access your unique referral code and tracking links.</li>
        <li style="margin-bottom: 6px;">Track client bookings generated through your network.</li>
        <li style="margin-bottom: 6px;">Monitor commissions, payouts, and lifetime earnings.</li>
        <li style="margin-bottom: 6px;">Manage your partner profile and payout details.</li>
    </ul>

    <div style="background: #18181b; border: 1px solid #1f1f1f; padding: 16px; border-radius: 6px; margin: 24px 0;">
        <p style="font-size: 13px; color: #94a3b8; margin: 0 0 4px;">Partner Dashboard Login:</p>
        <p style="font-size: 13px; color: #13ff01; margin: 0; font-family: monospace;"><a href="{{ config('app.frontend_url') }}/partner/login" style="color: #13ff01; text-decoration: none;">{{ config('app.frontend_url') }}/partner/login</a></p>
    </div>

    <p style="font-size: 14px; color: #cbd5e1; margin: 24px 0 0;">Best regards,<br>The Illuvision Team</p>

    <div style="border-top: 1px solid #1f1f1f; padding-top: 16px; margin-top: 24px;">
        <p style="font-size: 12px; color: #64748b; margin: 0;">If you're having trouble with the button, copy and paste this URL into your browser:</p>
        <p style="font-size: 12px; color: #13ff01; word-break: break-all; margin: 4px 0 0;"><a href="{{ $setupUrl }}" style="color: #13ff01; text-decoration: none;">{{ $setupUrl }}</a></p>
    </div>
@endcomponent