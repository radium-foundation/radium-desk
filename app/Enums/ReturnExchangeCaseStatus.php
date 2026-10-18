<?php

namespace App\Enums;

enum ReturnExchangeCaseStatus: string
{
    case Submitted = 'submitted';
    case Received = 'received';
    case Inspected = 'inspected';
    case UnderReview = 'under_review';
    case InformationNeeded = 'information_needed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Received => 'Received',
            self::Inspected => 'Inspected',
            self::UnderReview => 'Under review',
            self::InformationNeeded => 'Information needed',
            self::Closed => 'Closed',
        };
    }
}
