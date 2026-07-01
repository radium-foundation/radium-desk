<?php

namespace App\Enums;

enum AutomationExecutionStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
