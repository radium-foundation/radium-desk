<?php

namespace App\Support\Repair\Enums;

enum RepairPhase: string
{
    case Preview = 'preview';
    case Execute = 'execute';
    case Verify = 'verify';
    case Rollback = 'rollback';
    case Archive = 'archive';
}
