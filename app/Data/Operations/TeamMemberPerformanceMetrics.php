<?php

namespace App\Data\Operations;

readonly class TeamMemberPerformanceMetrics
{
    /**
     * @param  array<string, int|float|string|null>  $attendance
     * @param  array<string, int|float|string|null>  $login
     * @param  array<string, int|float|string|null>  $presence
     * @param  array<string, int|float|string|null>  $customer_work
     * @param  array<string, int|float|string|null>  $quality
     */
    public function __construct(
        public int $userId,
        public string $name,
        public ?string $roleLabel,
        public PerformancePeriodRange $range,
        public array $attendance,
        public array $login,
        public array $presence,
        public array $customerWork,
        public array $quality,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'name' => $this->name,
            'role_label' => $this->roleLabel,
            'range' => [
                'period' => $this->range->period->value,
                'label' => $this->range->label(),
                'start' => $this->range->start->toDateString(),
                'end' => $this->range->end->toDateString(),
            ],
            'attendance' => $this->attendance,
            'login' => $this->login,
            'presence' => $this->presence,
            'customer_work' => $this->customerWork,
            'quality' => $this->quality,
        ];
    }
}
