<?php

namespace App\Enums;

enum PresenceActivityType: string
{
    case System = 'system';
    case Heartbeat = 'heartbeat';
    case CaseAction = 'case_action';
    case CustomerCommunication = 'customer_communication';
    case StatusChange = 'status_change';
}
