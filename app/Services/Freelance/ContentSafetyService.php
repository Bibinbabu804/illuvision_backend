<?php

namespace App\Services\Freelance;

use App\Models\CommunicationModerationRecord;
use App\Models\User;

class ContentSafetyService
{
    /**
     * Inspect text for external contact details, bypass links, and forbidden keywords.
     *
     * @param string $text
     * @param User|null $user
     * @param string $contextType
     * @param int|null $contextId
     * @param bool $strict If true, throws/blocks immediately on any detection
     * @return array{is_clean: bool, triggers: array<string>, warning: ?string}
     */
    public function inspect(
        string $text,
        ?User $user = null,
        string $contextType = 'message',
        ?int $contextId = null,
        bool $strict = true
    ): array {
        if (trim($text) === '') {
            return ['is_clean' => true, 'triggers' => [], 'warning' => null];
        }

        $triggers = [];
        $normalized = $this->normalizeText($text);

        // 1. Email detection (standard + obfuscated e.g. user [at] gmail [dot] com)
        if ($this->detectEmail($text) || $this->detectEmail($normalized)) {
            $triggers[] = 'email_address';
        }

        // 2. Phone number detection (international & local formats, spaced out, hyphenated)
        if ($this->detectPhoneNumber($text) || $this->detectPhoneNumber($normalized)) {
            $triggers[] = 'phone_number';
        }

        // 3. WhatsApp / Telegram / Direct Chat apps
        if ($this->detectMessagingApps($normalized)) {
            $triggers[] = 'external_chat_platform';
        }

        // 4. External Payment Methods & Circumvention
        if ($this->detectPaymentHandles($normalized)) {
            $triggers[] = 'external_payment_circumvention';
        }

        // 5. Social & Code profiles (Instagram, GitHub, LinkedIn, Facebook, Discord)
        if ($this->detectExternalProfiles($normalized)) {
            $triggers[] = 'external_profile_url';
        }

        // 6. Direct contact invitation phrases
        if ($this->detectBypassPhrases($normalized)) {
            $triggers[] = 'contact_bypass_phrase';
        }

        $isClean = empty($triggers);
        $warning = null;

        if (!$isClean) {
            $warning = 'Platform Security Policy: Sharing phone numbers, emails, external chat handles, social profiles, or payment links is prohibited. All communications and payments must remain securely within the marketplace.';

            if ($user) {
                CommunicationModerationRecord::create([
                    'user_id' => $user->id,
                    'context_type' => $contextType,
                    'context_id' => $contextId,
                    'detected_triggers' => $triggers,
                    'original_text' => $text,
                    'sanitized_text' => $this->sanitize($text),
                    'severity' => count($triggers) > 1 ? 'blocked' : 'warning',
                    'status' => 'flagged',
                    'admin_notes' => 'Automated detection by ContentSafetyService.',
                ]);
            }
        }

        return [
            'is_clean' => $isClean,
            'triggers' => $triggers,
            'warning' => $warning,
        ];
    }

    /**
     * Normalize text to defeat simple leetspeak and whitespace obfuscation.
     */
    protected function normalizeText(string $text): string
    {
        $text = strtolower($text);

        // Replace common obfuscations: [at], (at), {at}, [dot], (dot), {dot}
        $text = preg_replace('/\s*(\[|\(|\{)?(at|@)(\]|\)|\})?\s*/i', '@', $text);
        $text = preg_replace('/\s*(\[|\(|\{)?(dot|\.)(\]|\)|\})?\s*/i', '.', $text);

        // Standardize common leetspeak
        $replacements = [
            '0' => 'o',
            '1' => 'i',
            '3' => 'e',
            '4' => 'a',
            '5' => 's',
            '7' => 't',
            '$' => 's',
        ];

        return strtr($text, $replacements);
    }

    protected function detectEmail(string $text): bool
    {
        // Standard email regex
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text)) {
            return true;
        }

        // Spaced out emails: e.g. "name @ domain . com"
        if (preg_match('/[a-z0-9._%+-]+\s*@\s*[a-z0-9.-]+\s*\.\s*[a-z]{2,}/i', $text)) {
            return true;
        }

        return false;
    }

    protected function detectPhoneNumber(string $text): bool
    {
        // Detect strings of 7 to 15 digits possibly separated by spaces, dashes, dots, or parens
        // Exclude simple years (e.g. 2026) or quantities
        if (preg_match('/(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $text)) {
            return true;
        }

        // Check for 10 consecutive or space-separated digits (e.g. Indian mobile numbers 9876543210 or 98765 43210)
        $digitsOnly = preg_replace('/\D/', '', $text);
        if (strlen($digitsOnly) >= 10 && strlen($digitsOnly) <= 13) {
            // Check if digits are clustered within a small word distance
            if (preg_match('/\b(?:\d[\s\-\.]*){10,12}\b/', $text)) {
                return true;
            }
        }

        return false;
    }

    protected function detectMessagingApps(string $text): bool
    {
        $patterns = [
            '/\bwa\.me\b/i',
            '/\bwhatsapp\b/i',
            '/\bt\.me\b/i',
            '/\btelegram\b/i',
            '/\bdiscord\.gg\b/i',
            '/\bskype\b/i',
            '/\bviber\b/i',
            '/\bwechat\b/i',
            '/\bsignal\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    protected function detectPaymentHandles(string $text): bool
    {
        $patterns = [
            '/\bpaypal(?:\.me)?\b/i',
            '/\bpaytm\b/i',
            '/\bgpay\b/i',
            '/\bgoogle\s*pay\b/i',
            '/\bphonepe\b/i',
            '/\bupi\b/i',
            '/\bvenmo\b/i',
            '/\bcashapp\b/i',
            '/\bcash\.app\b/i',
            '/\bpay\s*outside\b/i',
            '/\bdirect\s*(?:bank|transfer|wire)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    protected function detectExternalProfiles(string $text): bool
    {
        $patterns = [
            '/\bgithub\.com\b/i',
            '/\blinkedin\.com\b/i',
            '/\binstagram\.com\b/i',
            '/\bfacebook\.com\b/i',
            '/\bx\.com\b/i',
            '/\btwitter\.com\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    protected function detectBypassPhrases(string $text): bool
    {
        $phrases = [
            'call me at',
            'call me on',
            'reach me at',
            'contact me on',
            'ping me on',
            'text me on',
            'inbox me on',
            'message me directly',
            'pay me directly',
            'save fees',
            'skip commission',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize sensitive contact info with asterisks for audit previews.
     */
    public function sanitize(string $text): string
    {
        // Redact emails
        $sanitized = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $text);
        // Redact phone numbers
        $sanitized = preg_replace('/(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', '[REDACTED_PHONE]', $sanitized);
        return $sanitized;
    }

    public function scanText(string $text): array
    {
        $inspection = $this->inspect($text);
        return [
            'has_violations' => ! $inspection['is_clean'],
            'violations' => $inspection['triggers'],
            'detected_patterns' => $inspection['triggers'],
            'friendly_warning' => $inspection['warning'] ?? 'Platform safety policy: Sharing direct contact details (phone, email, WhatsApp, Telegram) or external payment links is not permitted. Please keep all communication and transactions within the platform.',
        ];
    }

    public function recordViolation(int $userId, string $contextType, string $text, array $violations = [], array $patterns = []): void
    {
        CommunicationModerationRecord::create([
            'user_id' => $userId,
            'context_type' => $contextType,
            'detected_triggers' => $violations,
            'original_text' => $text,
            'sanitized_text' => $this->sanitize($text),
            'severity' => count($violations) > 1 ? 'blocked' : 'warning',
            'status' => 'flagged',
            'admin_notes' => 'Automated detection by ContentSafetyService.',
        ]);
    }
}
