<?php

namespace App\Data\Operations;

readonly class WorkingHoursToday
{
    public function __construct(
        public int $activeDurationSeconds,
        public ?string $label,
        public int $sessionCount = 0,
    ) {}

    public static function empty(): self
    {
        return new self(
            activeDurationSeconds: 0,
            label: null,
            sessionCount: 0,
        );
    }

    public function shouldDisplay(): bool
    {
        return $this->sessionCount > 0 || $this->activeDurationSeconds > 0;
    }

    /**
     * @return array{active_duration_seconds: int, label: string|null, session_count: int}
     */
    public function toArray(): array
    {
        return [
            'active_duration_seconds' => $this->activeDurationSeconds,
            'label' => $this->shouldDisplay() ? ($this->label ?? '0m') : null,
            'session_count' => $this->sessionCount,
        ];
    }
}
