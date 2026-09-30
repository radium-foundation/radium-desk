<?php

namespace App\CentralWallet\Domain\Enums;

enum BalanceMigrationStatus: string
{
    case Initiated = 'initiated';
    case Prepared = 'prepared';
    case CentralCredited = 'central_credited';
    case SourceRetired = 'source_retired';
    case Reconciled = 'reconciled';
    case Aborted = 'aborted';
    case Compensating = 'compensating';
}
