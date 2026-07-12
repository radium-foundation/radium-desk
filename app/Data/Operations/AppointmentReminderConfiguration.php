<?php

namespace App\Data\Operations;

readonly class AppointmentReminderConfiguration
{
    /**
     * @param  list<int>  $thresholdsMinutes
     */
    public function __construct(
        public bool $enabled,
        public array $thresholdsMinutes,
    ) {}

    public function isDisabled(): bool
    {
        return ! $this->enabled || $this->thresholdsMinutes === [];
    }
}
