<?php

namespace App\Support\Repair\Enums;

enum RepairItemOutcome: string
{
    case WouldRepair = 'would_repair';
    case WouldCleanup = 'would_cleanup';
    case WouldSkip = 'would_skip';
    case Repaired = 'repaired';
    case CleanedUp = 'cleaned_up';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
}
