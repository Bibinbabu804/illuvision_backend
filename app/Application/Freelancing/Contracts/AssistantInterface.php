<?php

namespace App\Application\Freelancing\Contracts;

interface AssistantInterface
{
    /** @param array<string, mixed> $context */
    public function reply(string $message, array $context = []): string;
}
