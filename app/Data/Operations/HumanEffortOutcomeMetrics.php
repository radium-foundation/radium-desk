<?php

namespace App\Data\Operations;

use App\Enums\OperationsKpiProfile;

readonly class HumanEffortOutcomeMetrics
{
    /**
     * @param  array<string, int|float|null>  $breakdown
     */
    public function __construct(
        public OperationsKpiProfile $profile,
        public int $outcome,
        public int $effort,
        public array $breakdown = [],
    ) {}

    public function outcomeLabel(): string
    {
        return $this->profile->outcomeLabel();
    }

    public function effortLabel(): string
    {
        return $this->profile->effortLabel();
    }

    public function averageOrdersPerSession(): ?float
    {
        if ($this->profile !== OperationsKpiProfile::Activation) {
            return null;
        }

        $sessions = (int) ($this->breakdown['activation_sessions'] ?? $this->effort);

        if ($sessions <= 0) {
            return null;
        }

        return round($this->outcome / $sessions, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile->value,
            'outcome' => $this->outcome,
            'effort' => $this->effort,
            'outcome_label' => $this->outcomeLabel(),
            'effort_label' => $this->effortLabel(),
            'breakdown' => $this->breakdown,
            'average_orders_per_session' => $this->averageOrdersPerSession(),
        ];
    }
}
