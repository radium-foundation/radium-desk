<?php

namespace App\Data\Operations;

readonly class SupportAppointmentReminderDiagnosticEntry
{
    /**
     * @param  array<string, bool>  $checks
     * @param  array<string, string>  $details
     */
    public function __construct(
        public int $appointmentId,
        public array $checks,
        public ?string $failureReason = null,
        public array $details = [],
    ) {}

    public function failedCheck(): ?string
    {
        foreach ($this->checks as $check => $passed) {
            if (! $passed) {
                return $check;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function detailLines(): array
    {
        $lines = [];

        foreach ($this->details as $label => $value) {
            if ($value === '') {
                continue;
            }

            $lines[$label] = $value;
        }

        return $lines;
    }
}
