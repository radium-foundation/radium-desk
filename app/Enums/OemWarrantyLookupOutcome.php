<?php

namespace App\Enums;

enum OemWarrantyLookupOutcome: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Inconclusive = 'inconclusive';
    case Error = 'error';
}
