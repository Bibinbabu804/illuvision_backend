<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $headerTitle ?? 'Notification' }}</title>
</head>
<body style="background-color: #080808; font-family: 'Inter Tight', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #e2e8f0; margin: 0; padding: 0; -webkit-font-smoothing: antialiased;">
    <div style="max-width: 600px; margin: 0 auto; padding: 24px 16px;">
        <!-- Logo Header -->
        <div style="text-align: center; padding: 20px 0 28px;">
            <h1 style="font-size: 22px; font-weight: 800; color: #ffffff; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; padding: 0; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                <span style="font-weight: 300;">ILLU</span><span style="color: #13ff01;">VISION</span>
            </h1>
        </div>

        <!-- Main Card Container -->
        @php
            // Normalize headerColor or map it to standard frontend statuses
            $accentColor = $headerColor ?? '#13ff01';
            // If the legacy template passes default dark blue, replace it with the brand neon green
            if ($accentColor === '#1a1a2e') {
                $accentColor = '#13ff01';
            }
        @endphp
        <div style="background-color: #121212; border: 1px solid #1f1f1f; border-top: 4px solid {{ $accentColor }}; border-radius: 8px; padding: 32px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);">
            <!-- Subject Title -->
            @if(isset($headerTitle))
                <h2 style="font-size: 18px; font-weight: 700; color: #ffffff; margin-top: 0; margin-bottom: 20px; border-bottom: 1px solid #1f1f1f; padding-bottom: 12px; letter-spacing: -0.01em;">
                    {{ $headerTitle }}
                </h2>
            @endif

            <!-- Content Slot -->
            <div style="font-size: 14px; color: #cbd5e1;">
                {{ $slot }}
            </div>
        </div>

        <!-- Footer -->
        <div style="text-align: center; margin-top: 28px; padding: 0 16px;">
            <p style="font-size: 11px; color: #64748b; margin: 0 0 6px; letter-spacing: 0.05em; text-transform: uppercase;">
                &copy; {{ date('Y') }} Illuvision. All rights reserved.
            </p>
            <p style="font-size: 11px; color: #475569; margin: 0;">
                This is an automated system notification. Please do not reply directly to this email.
            </p>
        </div>
    </div>
</body>
</html>
