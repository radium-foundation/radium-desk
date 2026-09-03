<?php

namespace App\Enums;

enum EInvoiceRecordStatus: string
{
    case Queued = 'queued';
    case Skipped = 'skipped';
    case Submitted = 'submitted';
    case Failed = 'failed';
}
