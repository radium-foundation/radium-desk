<?php

namespace App\Data\Operations;

readonly class WorkingHoursToday
{
    /**
     * @param  int|null  $minutesLate  Display minutes when the attendance register
     *                                 classifies today as Late; null otherwise.
     */
    public function __construct(
        public int $activeDurationSeconds,
        public ?string $label,
        public int $sessionCount = 0,
        public ?bool $onTimeLogin = null,
        public ?int $minutesLate = null,
    ) {}

    public static function empty(): self
    {
        return new self(
            activeDurationSeconds: 0,
            label: null,
            sessionCount: 0,
            onTimeLogin: null,
            minutesLate: null,
        );
    }

    public function shouldDisplay(): bool
    {
        return $this->sessionCount > 0 || $this->activeDurationSeconds > 0;
    }

    /**
     * @return array{
     *     active_duration_seconds: int,
     *     label: string|null,
     *     session_count: int,
     *     on_time_login: bool|null,
     *     minutes_late: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'active_duration_seconds' => $this->activeDurationSeconds,
            'label' => $this->shouldDisplay() ? ($this->label ?? '0m') : null,
            'session_count' => $this->sessionCount,
            'on_time_login' => $this->onTimeLogin,
            'minutes_late' => $this->minutesLate,
        ];
    }
}
