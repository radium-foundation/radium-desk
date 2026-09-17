<?php

namespace App\Enums;

enum EInvoiceRecordStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Skipped = 'skipped';
    case Submitted = 'submitted';
    case Failed = 'failed';
    case TemporaryFailure = 'temporary_failure';
    case PermanentFailure = 'permanent_failure';
    case Ambiguous = 'ambiguous';
    case IrnNotFound = 'irn_not_found';
}
