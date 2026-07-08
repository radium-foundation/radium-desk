<?php

namespace App\Enums;

enum BonvoiceCallAlertType: string
{
    case CustomerFound = 'customer_found';
    case UnknownCaller = 'unknown_caller';
}
