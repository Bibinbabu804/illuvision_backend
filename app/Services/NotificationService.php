<?php

namespace App\Services;
use App\Infrastructure\Notifications\Contracts\MailContractInterface;
use App\Models\NotificationLog;
use App\Support\Configs\NotificationConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * NotificationService - Centralized notification dispatch service.
 *
 * Responsibilities:
 * 1. Read notification settings for a given event
 * 2. Determine recipients (admin, user, partner)
 * 3. Determine channels (email, in-app)
 * 4. Queue notifications through the appropriate channels
 * 5. Log all notification attempts for audit/debugging
 *
 * This is the ONLY place where notification mail should be dispatched from;
 * controllers and domain services dispatch events instead. The transport itself
 * is reached through MailContractInterface, so this class no longer depends on
 * the Mail facade.
 */
class NotificationService
{
    protected NotificationConfig $config;

    public function __construct(
        NotificationConfig $config,
        protected MailContractInterface $mail,
    ) {
        $this->config = $config;
    }

    /**
     * Get admin recipient email addresses from notification settings.
     * Uses the configured admin_emails from Notification Settings.
     * Does NOT query the users table — operations teams have full control.
     *
     * @return array{emails: array, fallback: string|null}
     */
    public function getAdminRecipients(): array
    {
        $emails = $this->config->adminEmails();
        $fallback = $this->config->adminEmail();

        return [
            'emails' => $emails,
            'fallback' => $fallback,
        ];
    }

    /**
     * Send a notification for a given event.
     *
     * @param  string  $eventKey  e.g. 'booking_created'
     * @param  array  $recipients  ['user' => User|null, 'partner' => Partner|array|null, 'admin' => bool]
     * @param  string  $mailableClass  FQCN of the Mailable to send
     * @param  array  $data  Data to pass to the mailable constructor
     */
    public function send(
        string $eventKey,
        array $recipients,
        string $mailableClass,
        array $data = []
    ): void {
        $config = $this->config->forEvent($eventKey);

        if (! $config['enabled']) {
            Log::debug("NotificationService: Event [{$eventKey}] is disabled in settings. Skipping.");

            return;
        }

        // ── Check global email channel toggle ────────────────
        $emailGloballyEnabled = $this->config->isEmailEnabled();

        // ── Resolve recipients ───────────────────────────────
        $emailRecipients = [];

        // Admin recipients — from configured admin_emails ONLY
        if ($config['send_to_admin'] && ! empty($recipients['admin'])) {
            $adminInfo = $this->getAdminRecipients();
            foreach ($adminInfo['emails'] as $email) {
                if (! empty($email)) {
                    $emailRecipients[] = [
                        'email' => $email,
                        'name' => 'Admin',
                        'type' => 'admin',
                        'user_id' => null,
                    ];
                }
            }
            // Fallback to support email if no admin_emails configured
            if (empty($adminInfo['emails']) && $adminInfo['fallback']) {
                $emailRecipients[] = [
                    'email' => $adminInfo['fallback'],
                    'name' => 'Admin',
                    'type' => 'admin',
                    'user_id' => null,
                ];
            }
        }

        // User recipient
        $user = $recipients['user'] ?? null;
        if ($config['send_to_user'] && $user && ! empty($user->email)) {
            $emailRecipients[] = [
                'email' => $user->email,
                'name' => $user->name ?? 'User',
                'type' => 'user',
                'user_id' => $user->id ?? null,
            ];
        }

        // Partner recipient
        $partner = $recipients['partner'] ?? null;
        if ($config['send_to_partner'] && $partner) {
            $partnerEmail = $partner['email'] ?? ($partner->email ?? null);
            $partnerName = $partner['name'] ?? ($partner->name ?? 'Partner');
            if ($partnerEmail) {
                $emailRecipients[] = [
                    'email' => $partnerEmail,
                    'name' => $partnerName,
                    'type' => 'partner',
                    'user_id' => null,
                ];
            }
        }

        if (empty($emailRecipients)) {
            Log::debug("NotificationService: Event [{$eventKey}] has no recipients. Skipping.");

            return;
        }

        // ── Deduplicate recipients by email address ───────────
        // Prevents sending duplicate notifications when, for example,
        // the same email is configured both as an admin recipient AND
        // as the booking user's email, or when a duplicate exists in
        // admin_emails.
        $seen = [];
        $emailRecipients = array_values(array_filter($emailRecipients, function ($r) use (&$seen) {
            $key = strtolower($r['email'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));

        // ── Send via email channel ────────────────────────────
        if ($emailGloballyEnabled && $config['channel_email'] && $this->isMailConfigured()) {
            foreach ($emailRecipients as $recipient) {
                try {
                    $this->sendEmail($mailableClass, $recipient, $data);

                    $this->logNotification(
                        $eventKey,
                        $recipient,
                        'email',
                        'sent',
                        null
                    );
                } catch (\Throwable $e) {
                    Log::error("NotificationService: Failed to send [{$eventKey}] email to {$recipient['email']}: {$e->getMessage()}");

                    $this->logNotification(
                        $eventKey,
                        $recipient,
                        'email',
                        'failed',
                        $e->getMessage()
                    );
                }
            }
        }

        // ── In-app channel (placeholder for future) ───────────
        if ($config['channel_in_app']) {
            // @todo: Implement in-app notification channel
            // This would create an in-app notification record for each recipient
            Log::debug("NotificationService: In-app channel not yet implemented for [{$eventKey}]");
        }
    }

    /**
     * Send a single email via a mailable class.
     */
    protected function sendEmail(string $mailableClass, array $recipient, array $data): void
    {
        if (! class_exists($mailableClass)) {
            throw new \InvalidArgumentException("Mailable class [{$mailableClass}] does not exist.");
        }

        $mailable = new $mailableClass(...array_values($data));

        if ($mailable instanceof ShouldQueue) {
            $this->mail->queue($recipient['email'], $recipient['name'], $mailable);
        } else {
            $this->mail->send($recipient['email'], $recipient['name'], $mailable);
        }
    }

    /**
     * Log a notification attempt to the notification_logs table.
     */
    protected function logNotification(
        string $eventKey,
        array $recipient,
        string $channel,
        string $status,
        ?string $failedReason = null
    ): void {
        try {
            NotificationLog::create([
                'event' => $eventKey,
                'recipient_type' => $recipient['type'] ?? 'unknown',
                'recipient_email' => $recipient['email'] ?? null,
                'recipient_id' => $recipient['user_id'] ?? null,
                'channel' => $channel,
                'status' => $status,
                'failed_reason' => $failedReason,
                'sent_at' => $status === 'sent' ? now() : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning("NotificationService: Failed to log notification: {$e->getMessage()}");
        }
    }

    /**
     * Check if mail is configured (not using 'log' driver).
     */
    protected function isMailConfigured(): bool
    {
        $driver = config('mail.default');

        // 'log' and 'array' drivers don't actually send emails
        return ! in_array($driver, ['log', 'array']);
    }
}
