<?php

namespace App\Enums;

enum EInvoiceSubmitOutcome: string
{
    case Skipped = 'skipped';
    case Success = 'success';
    case TemporaryFailure = 'temporary_failure';
    case PermanentFailure = 'permanent_failure';
    case Ambiguous = 'ambiguous';
}
