<?php

namespace App\Support\Repair\Enums;

enum RepairBatchStatus: string
{
    case Previewed = 'previewed';
    case Approved = 'approved';
    case Running = 'running';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';
    case RollingBack = 'rolling_back';
    case RolledBack = 'rolled_back';
    case Archived = 'archived';
}
