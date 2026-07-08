<?php

namespace App\Enums;

enum BonvoiceCallLinkType: string
{
    case Missed = 'missed';
    case Answered = 'answered';
}
