<?php

namespace App\Enums;

enum MissingSerialAutomationAction: string
{
    case Request = 'request';
    case Reminder = 'reminder';
    case Escalate = 'escalate';
}
