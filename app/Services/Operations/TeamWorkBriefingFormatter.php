<?php

namespace App\Services\Operations;

use App\Data\Operations\TeamWorkBriefing;
use App\Enums\SupportAppointmentTimeSlot;

class TeamWorkBriefingFormatter
{
    public function format(TeamWorkBriefing $briefing, ?string $recipientFirstName = null): string
    {
        $lines = [];

        if ($recipientFirstName !== null && $recipientFirstName !== '') {
            $lines[] = 'Good morning '.$recipientFirstName.'.';
            $lines[] = '';
        }

        $lines[] = "Today's Work:";
        $lines[] = '';
        $lines[] = 'Support:';
        $lines[] = 'Morning: '.($briefing->supportBySlot[SupportAppointmentTimeSlot::Morning->value] ?? 0);
        $lines[] = 'Afternoon: '.($briefing->supportBySlot[SupportAppointmentTimeSlot::Afternoon->value] ?? 0);

        $eveningCount = $briefing->supportBySlot[SupportAppointmentTimeSlot::Evening->value] ?? 0;

        if ($eveningCount > 0) {
            $lines[] = 'Evening: '.$eveningCount;
        }

        $lines[] = '';
        $lines[] = 'Follow-ups:';
        $lines[] = $briefing->followUpCount.' waiting customers';
        $lines[] = '';
        $lines[] = 'Priority:';
        $lines[] = $briefing->priorityCount.' need attention';

        return implode("\n", $lines);
    }
}
