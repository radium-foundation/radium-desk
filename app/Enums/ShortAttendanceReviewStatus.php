<?php

namespace App\Enums;

enum ShortAttendanceReviewStatus: string
{
    case PendingReview = 'pending_review';
    case Decided = 'decided';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending Review',
            self::Decided => 'Decided',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
