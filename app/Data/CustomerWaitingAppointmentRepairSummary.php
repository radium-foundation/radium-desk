<?php

namespace App\Data;

readonly class CustomerWaitingAppointmentRepairSummary
{
    /**
     * @param  list<array<string, mixed>>  $samples
     */
    public function __construct(
        public bool $dryRun,
        public int $appointmentsFound,
        public int $waitingStatesCleared,
        public int $skipped,
        public array $samples = [],
        public ?string $configurationError = null,
    ) {}
}
