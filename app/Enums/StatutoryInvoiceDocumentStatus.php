<?php

namespace App\Enums;

enum StatutoryInvoiceDocumentStatus: string
{
    case Pending = 'pending';
    case Generated = 'generated';
    case Failed = 'failed';
}
