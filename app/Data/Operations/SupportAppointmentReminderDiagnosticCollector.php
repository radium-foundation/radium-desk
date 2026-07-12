<?php

namespace App\Data\Operations;

class SupportAppointmentReminderDiagnosticCollector
{
    public bool $globalEnabled = true;

    public int $scheduledAppointments = 0;

    public int $todaysAppointments = 0;

    public int $withAssignedEngineer = 0;

    public int $passedQuietRules = 0;

    public int $validSlotConfiguration = 0;

    public int $matchedReminderWindow = 0;

    public bool $verbose = false;

    /** @var list<SupportAppointmentReminderDiagnosticEntry> */
    public array $verboseEntries = [];

    public function toDiagnostics(): SupportAppointmentReminderDiagnostics
    {
        return new SupportAppointmentReminderDiagnostics(
            globalEnabled: $this->globalEnabled,
            scheduledAppointments: $this->scheduledAppointments,
            todaysAppointments: $this->todaysAppointments,
            withAssignedEngineer: $this->withAssignedEngineer,
            passedQuietRules: $this->passedQuietRules,
            validSlotConfiguration: $this->validSlotConfiguration,
            matchedReminderWindow: $this->matchedReminderWindow,
            verboseEntries: $this->verboseEntries,
        );
    }
}
