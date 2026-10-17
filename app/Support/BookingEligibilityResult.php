<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class BookingEligibilityResult implements Arrayable, JsonSerializable
{
    /**
     * Error Code Constants
     */
    const CODE_DAILY_CAPACITY_EXCEEDED = 'SCREEN_DAILY_CAPACITY_EXCEEDED';
    const CODE_TIME_CONFLICT = 'SCREEN_TIME_CONFLICT';
    const CODE_FLEET_CAPACITY_EXCEEDED = 'SCREEN_FLEET_CAPACITY_EXCEEDED';
    const CODE_ASSET_UNAVAILABLE = 'SCREEN_ASSET_UNAVAILABLE';
    const CODE_INVALID_INTERVAL = 'INVALID_TIME_INTERVAL';

    /**
     * Conflict Type Constants
     */
    const TYPE_DAILY_LIMIT = 'daily_limit';
    const TYPE_TIME_CONFLICT = 'time_conflict';
    const TYPE_FLEET_CAPACITY = 'fleet_capacity';
    const TYPE_ASSET_UNAVAILABLE = 'asset_unavailable';

    public function __construct(
        public bool $eligible = true,
        public ?string $errorCode = null,
        public ?string $conflictType = null,
        public array $conflicts = [],
        public array $dailyCapacityBreakdown = [],
        public array $physicalCapacityBreakdown = [],
        public array $conflictDates = [],
        public array $screens = [],
        public array $details = []
    ) {}

    public static function eligible(
        array $dailyBreakdown = [],
        array $physicalBreakdown = [],
        array $screens = [],
        array $details = []
    ): self {
        return new self(
            eligible: true,
            errorCode: null,
            conflictType: null,
            conflicts: [],
            dailyCapacityBreakdown: $dailyBreakdown,
            physicalCapacityBreakdown: $physicalBreakdown,
            conflictDates: [],
            screens: $screens,
            details: $details
        );
    }

    public static function ineligible(
        string $errorCode,
        string $conflictType,
        array $conflicts,
        array $conflictDates = [],
        array $dailyBreakdown = [],
        array $physicalBreakdown = [],
        array $screens = [],
        array $details = []
    ): self {
        return new self(
            eligible: false,
            errorCode: $errorCode,
            conflictType: $conflictType,
            conflicts: $conflicts,
            dailyCapacityBreakdown: $dailyBreakdown,
            physicalCapacityBreakdown: $physicalBreakdown,
            conflictDates: array_values(array_unique($conflictDates)),
            screens: $screens,
            details: $details
        );
    }

    public function isEligible(): bool
    {
        return $this->eligible;
    }

    public function isAvailable(): bool
    {
        return $this->eligible;
    }

    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    public function getConflictMessages(): array
    {
        $messages = [];
        foreach ($this->conflicts as $c) {
            if (is_string($c)) {
                $messages[] = $c;
            } elseif (is_array($c) && isset($c['message'])) {
                $messages[] = $c['message'];
            }
        }
        return array_values(array_unique($messages));
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getConflictType(): ?string
    {
        return $this->conflictType;
    }

    public function getConflictDates(): array
    {
        return array_values(array_unique($this->conflictDates));
    }

    public function getScreens(): array
    {
        return array_values($this->screens);
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function getFirstErrorMessage(): ?string
    {
        if (empty($this->conflicts)) {
            return null;
        }

        $first = $this->conflicts[0];
        if (is_string($first)) {
            return $first;
        }
        if (is_array($first)) {
            return $first['message'] ?? $first['reason'] ?? json_encode($first);
        }

        return (string) $first;
    }

    public function toArray(): array
    {
        $firstMsg = $this->getFirstErrorMessage();

        return [
            'success' => $this->eligible,
            'available' => $this->eligible,
            'eligible' => $this->eligible,
            'error_code' => $this->errorCode,
            'conflict_type' => $this->conflictType,
            'message' => $firstMsg ?? ($this->eligible ? 'All requested assets are available.' : 'Selected assets are not available for booking.'),
            'conflicts' => $this->getConflictMessages(),
            'first_error' => $firstMsg,
            'conflict_dates' => array_values(array_unique($this->conflictDates)),
            'screens' => array_values($this->screens),
            'details' => $this->details,
            'daily_capacity' => $this->dailyCapacityBreakdown,
            'physical_capacity' => $this->physicalCapacityBreakdown,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
