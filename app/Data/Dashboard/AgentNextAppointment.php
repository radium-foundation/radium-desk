<?php

namespace App\Data\Dashboard;

use Illuminate\Support\Carbon;

readonly class AgentNextAppointment
{
    public function __construct(
        public int $incidentId,
        public string $customerName,
        public ?string $deviceModel,
        public Carbon $startsAt,
        public bool $isOverdue,
    ) {}

    public function minutesUntil(?Carbon $now = null): int
    {
        $now ??= now();

        return (int) $now->diffInMinutes($this->startsAt, false);
    }

    public function isImminent(?Carbon $now = null): bool
    {
        $now ??= now();
        $minutes = $this->minutesUntil($now);

        return $this->isOverdue || ($minutes >= 0 && $minutes <= 30);
    }

    public function timeLabel(): string
    {
        return $this->startsAt->format('g:i A');
    }

    public function startsInLabel(?Carbon $now = null): string
    {
        $now ??= now();
        $minutes = $this->minutesUntil($now);

        if ($this->isOverdue || $minutes < 0) {
            $overdueMinutes = abs($minutes);

            return $overdueMinutes < 60
                ? "Overdue by {$overdueMinutes} minute".($overdueMinutes === 1 ? '' : 's')
                : 'Overdue';
        }

        if ($minutes === 0) {
            return 'Starting now';
        }

        if ($minutes < 60) {
            return "Starts in {$minutes} minute".($minutes === 1 ? '' : 's');
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        if ($remaining === 0) {
            return "Starts in {$hours} hour".($hours === 1 ? '' : 's');
        }

        return "Starts in {$hours}h {$remaining}m";
    }

    /**
     * @return array{
     *     incident_id: int,
     *     customer_name: string,
     *     device_model: ?string,
     *     starts_at: string,
     *     time_label: string,
     *     starts_in_label: string,
     *     minutes_until: int,
     *     is_overdue: bool,
     *     is_imminent: bool,
     * }
     */
    public function toArray(?Carbon $now = null): array
    {
        $now ??= now();

        return [
            'incident_id' => $this->incidentId,
            'customer_name' => $this->customerName,
            'device_model' => $this->deviceModel,
            'starts_at' => $this->startsAt->toIso8601String(),
            'time_label' => $this->timeLabel(),
            'starts_in_label' => $this->startsInLabel($now),
            'minutes_until' => $this->minutesUntil($now),
            'is_overdue' => $this->isOverdue,
            'is_imminent' => $this->isImminent($now),
        ];
    }
}
