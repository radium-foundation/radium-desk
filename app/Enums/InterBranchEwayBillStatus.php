<?php

namespace App\Enums;

enum InterBranchEwayBillStatus: string
{
    case NotApplicable = 'not_applicable';
    case ReferenceEntered = 'reference_entered';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Not applicable / not entered',
            self::ReferenceEntered => 'Reference entered (not government-generated)',
        };
    }
}
