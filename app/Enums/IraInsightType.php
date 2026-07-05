<?php

namespace App\Enums;

enum IraInsightType: string
{
    case Briefing = 'briefing';
    case Risk = 'risk';
    case Recommendation = 'recommendation';

    public function label(): string
    {
        return match ($this) {
            self::Briefing => 'Briefing',
            self::Risk => 'Risk',
            self::Recommendation => 'Recommendation',
        };
    }
}
