<?php

namespace App\Enums;

enum EInvoiceIssuanceKind: string
{
    case Hardware = 'hardware';
    case Service = 'service';
    case Mixed = 'mixed';
    case Unknown = 'unknown';
}
