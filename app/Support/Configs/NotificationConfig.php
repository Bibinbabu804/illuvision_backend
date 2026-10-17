<?php

namespace App\Support\Configs;

use App\Services\SettingsService;

class NotificationConfig
{
    private SettingsService $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    public function forEvent(string $eventKey): array
    {
        $config = $this->settings->getArray('notifications', $eventKey . '_config', self::eventDefaults($eventKey));

        return [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'send_to_admin' => (bool) ($config['send_to_admin'] ?? false),
            'send_to_user' => (bool) ($config['send_to_user'] ?? false),
            'send_to_partner' => (bool) ($config['send_to_partner'] ?? false),
            'channel_email' => (bool) ($config['channel_email'] ?? true),
            'channel_in_app' => (bool) ($config['channel_in_app'] ?? false),
        ];
    }

    public function isEnabled(string $eventKey): bool
    {
        return $this->forEvent($eventKey)['enabled'];
    }

    public function adminEmails(): array
    {
        return $this->settings->getList('notifications', 'admin_emails', []);
    }

    public function isEmailEnabled(): bool
    {
        return $this->settings->getBool('notifications', 'email_notifications', true);
    }

    public function adminEmail(): ?string
    {
        $emails = $this->adminEmails();
        return !empty($emails) ? $emails[0] : $this->settings->get('general', 'support_email', null);
    }

    public static function availableEvents(): array
    {
        return [
            'booking_created',
            'booking_approved',
            'booking_rejected',
            'booking_cancelled',
            'media_uploaded',
            'media_approved',
            'media_rejected',
            'payment_received',
            'payment_failed',
            'payment_refunded',
            'referral_used',
            'commission_generated',
            'partner_created',
            'partner_approved',
            'partner_rejected',
            'user_registered',
            'profile_updated',
            'product_enquiry_submitted',
            'contact_enquiry_submitted',
            'contact_enquiry_assigned',
            'contact_enquiry_closed',
            'contact_enquiry_auto_reply',
            'screen_provider_enquiry_submitted',
            'screen_provider_lead_assigned',
            'screen_provider_lead_converted',
            'screen_provider_lead_closed',
            'screen_provider_enquiry_auto_reply',
            'screen_provider_created',
            'screen_provider_approved',
            'screen_provider_rejected',
            'screen_provider_portal_invitation',
            'rewards_earned',
            'rewards_redeemed',
            'rewards_bonus_awarded',
            'rewards_expiring_soon',
        ];
    }

    public static function eventLabels(): array
    {
        return [
            'booking_created' => 'Booking Created',
            'booking_approved' => 'Booking Approved',
            'booking_rejected' => 'Booking Rejected',
            'booking_cancelled' => 'Booking Cancelled',
            'media_uploaded' => 'Media Uploaded',
            'media_approved' => 'Media Approved',
            'media_rejected' => 'Media Rejected',
            'payment_received' => 'Payment Received',
            'payment_failed' => 'Payment Failed',
            'payment_refunded' => 'Payment Refunded',
            'referral_used' => 'Referral Used',
            'commission_generated' => 'Commission Generated',
            'partner_created' => 'Partner Created',
            'partner_approved' => 'Partner Approved',
            'partner_rejected' => 'Partner Rejected',
            'user_registered' => 'User Registered',
            'profile_updated' => 'Profile Updated',
            'product_enquiry_submitted' => 'Product Enquiry Submitted',
            'contact_enquiry_submitted' => 'Contact Enquiry Submitted',
            'contact_enquiry_assigned' => 'Contact Enquiry Assigned',
            'contact_enquiry_closed' => 'Contact Enquiry Closed',
            'contact_enquiry_auto_reply' => 'Contact Auto Reply',
            'screen_provider_enquiry_submitted' => 'Screen Provider Enquiry Submitted',
            'screen_provider_lead_assigned' => 'Screen Provider Lead Assigned',
            'screen_provider_lead_converted' => 'Screen Provider Lead Converted',
            'screen_provider_lead_closed' => 'Screen Provider Lead Closed',
            'screen_provider_enquiry_auto_reply' => 'Screen Provider Auto Reply',
            'screen_provider_created' => 'Screen Provider Created',
            'screen_provider_approved' => 'Screen Provider Approved',
            'screen_provider_rejected' => 'Screen Provider Rejected',
            'screen_provider_portal_invitation' => 'Screen Provider Portal Invitation',
            'rewards_earned' => 'Rewards Earned',
            'rewards_redeemed' => 'Rewards Redeemed',
            'rewards_bonus_awarded' => 'Rewards Bonus Awarded',
            'rewards_expiring_soon' => 'Rewards Expiring Soon',
        ];
    }

    public static function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'send_to_admin' => false,
            'send_to_user' => false,
            'send_to_partner' => false,
            'channel_email' => true,
            'channel_in_app' => false,
        ];
    }

    public static function eventDefaults(string $eventKey): array
    {
        $defaults = [
            'booking_created' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'booking_approved' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'booking_rejected' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'booking_cancelled' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'media_uploaded' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'media_approved' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'media_rejected' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'payment_received' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'payment_failed' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'payment_refunded' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'referral_used' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => true, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'commission_generated' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => true, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'partner_created' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'partner_approved' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => false, 'send_to_partner' => true, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'partner_rejected' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => false, 'send_to_partner' => true, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'user_registered' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'profile_updated' => [
                'enabled' => false, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => false, 'channel_in_app' => false,
            ],
            'contact_enquiry_submitted' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'contact_enquiry_assigned' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'contact_enquiry_closed' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'contact_enquiry_auto_reply' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'product_enquiry_submitted' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'screen_provider_enquiry_submitted' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'screen_provider_lead_assigned' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'screen_provider_lead_converted' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'screen_provider_lead_closed' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'screen_provider_enquiry_auto_reply' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'screen_provider_created' => [
                'enabled' => true, 'send_to_admin' => true, 'send_to_user' => false, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'screen_provider_approved' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => false, 'send_to_partner' => true, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'screen_provider_rejected' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => false, 'send_to_partner' => true, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'screen_provider_portal_invitation' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => false, 'send_to_partner' => true, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'rewards_earned' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'rewards_redeemed' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'rewards_bonus_awarded' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
            'rewards_expiring_soon' => [
                'enabled' => true, 'send_to_admin' => false, 'send_to_user' => true, 'send_to_partner' => false, 'channel_email' => true, 'channel_in_app' => false,
            ],
        ];

        return $defaults[$eventKey] ?? self::defaultConfig();
    }
}
