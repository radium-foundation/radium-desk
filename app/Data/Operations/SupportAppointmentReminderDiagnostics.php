<?php

namespace App\Data\Operations;

readonly class SupportAppointmentReminderDiagnostics
{
    /**
     * @param  list<SupportAppointmentReminderDiagnosticEntry>  $verboseEntries
     */
    public function __construct(
        public bool $globalEnabled = true,
        public int $scheduledAppointments = 0,
        public int $todaysAppointments = 0,
        public int $withAssignedEngineer = 0,
        public int $passedQuietRules = 0,
        public int $validSlotConfiguration = 0,
        public int $matchedReminderWindow = 0,
        public array $verboseEntries = [],
    ) {}
}
