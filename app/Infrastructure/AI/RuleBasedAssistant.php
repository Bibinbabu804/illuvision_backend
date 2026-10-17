<?php

namespace App\Infrastructure\AI;

use App\Application\Freelancing\Contracts\AssistantInterface;

class RuleBasedAssistant implements AssistantInterface
{
    public function reply(string $message, array $context = []): string
    {
        $text = mb_strtolower($message);
        if (str_contains($text, 'payment') || str_contains($text, 'escrow') || str_contains($text, 'safe')) {
            return 'Payments are verified server-side and held until the client approves the submitted work. The frontend cannot release funds by itself.';
        }
        if (str_contains($text, 'freelancer') || str_contains($text, 'hire') || str_contains($text, 'talent')) {
            return 'You can browse active freelancer services, open a service package, and send a project request with your requirements and files.';
        }
        if (str_contains($text, 'price') || str_contains($text, 'cost') || str_contains($text, 'budget')) {
            return 'Each active service exposes its package price, delivery time and revision limit before you create a project request.';
        }
        if (str_contains($text, 'revision') || str_contains($text, 'delivery')) {
            return 'Project delivery and revisions are tracked by the project state machine. A client can request revisions before approving submitted work.';
        }
        return 'Tell me what you want to build, your budget or your timeline, and I can help you find the right IlluVision service.';
    }
}
