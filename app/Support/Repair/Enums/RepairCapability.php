<?php

namespace App\Support\Repair\Enums;

enum RepairCapability: string
{
    case Rollback = 'rollback';
    case Verify = 'verify';
    case Export = 'export';
    case Resume = 'resume';
    case Notify = 'notify';
}
