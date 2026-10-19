<?php

namespace App\Services\Freelance;

use App\Models\FreelancerProfile;
use App\Models\FreelanceConversation;
use App\Models\FreelanceMessage;
use Carbon\Carbon;

class ResponseTimeCalculatorService
{
    /**
     * Calculate and persist the average response time for a freelancer profile.
     *
     * @param FreelancerProfile $profile
     * @return array{seconds: ?int, formatted: string}
     */
    public function calculateForProfile(FreelancerProfile $profile): array
    {
        $userId = $profile->user_id;
        $since = Carbon::now()->subDays(90);

        // Find all conversations involving this freelancer
        $conversationIds = FreelanceConversation::whereHas('participants', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->pluck('id');

        if ($conversationIds->isEmpty()) {
            return $this->formatResult($profile, null);
        }

        $messages = FreelanceMessage::whereIn('conversation_id', $conversationIds)
            ->where('created_at', '>=', $since)
            ->where('message_type', '!=', 'system')
            ->orderBy('conversation_id')
            ->orderBy('created_at')
            ->get();

        $durations = [];
        $grouped = $messages->groupBy('conversation_id');

        foreach ($grouped as $convMessages) {
            $waitingForReply = false;
            $clientMessageTime = null;

            foreach ($convMessages as $msg) {
                if ($msg->sender_id !== $userId) {
                    // Message from client
                    if (!$waitingForReply) {
                        $waitingForReply = true;
                        $clientMessageTime = $msg->created_at;
                    }
                } else {
                    // Message from freelancer
                    if ($waitingForReply && $clientMessageTime) {
                        $diffInSeconds = $msg->created_at->diffInSeconds($clientMessageTime);
                        if ($diffInSeconds >= 0 && $diffInSeconds <= 86400 * 14) { // exclude anomalies > 14 days
                            $durations[] = $diffInSeconds;
                        }
                        $waitingForReply = false;
                        $clientMessageTime = null;
                    }
                }
            }
        }

        if (empty($durations)) {
            return $this->formatResult($profile, null);
        }

        // Use median to avoid outliers
        sort($durations);
        $count = count($durations);
        $middle = floor($count / 2);
        $medianSeconds = ($count % 2 === 0)
            ? (int) round(($durations[$middle - 1] + $durations[$middle]) / 2)
            : (int) $durations[$middle];

        return $this->formatResult($profile, $medianSeconds);
    }

    public static function format(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return 'Response time not available yet.';
        }
        if ($seconds <= 3600) {
            return 'Usually responds within 1 hour';
        }
        if ($seconds <= 14400) {
            return 'Usually responds within 4 hours';
        }
        if ($seconds <= 86400) {
            return 'Usually responds within 1 day';
        }
        return 'Usually responds within a few days';
    }

    public function recordReply(FreelancerProfile $profile, FreelanceConversation $conversation, FreelanceMessage $message): void
    {
        $this->calculateForProfile($profile);
    }

    protected function formatResult(FreelancerProfile $profile, ?int $seconds): array
    {
        $text = self::format($seconds);

        $profile->update([
            'response_time' => $text,
            'response_time_seconds' => $seconds,
        ]);

        return [
            'seconds' => $seconds,
            'formatted' => $text,
        ];
    }
}
