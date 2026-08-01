<?php

namespace App\Enums;

enum RecognitionRecommendation: string
{
    case NoBenefit = 'no_benefit';
    case Appreciation = 'appreciation';
    case Bonus = 'bonus';
    case HalfExtra = 'half_extra';
    case FullExtra = 'full_extra';
    case CompOff = 'comp_off';
    case Ot = 'ot';

    public function label(): string
    {
        return match ($this) {
            self::NoBenefit => 'No Benefit',
            self::Appreciation => 'Appreciation',
            self::Bonus => 'Bonus',
            self::HalfExtra => 'Half Extra',
            self::FullExtra => 'Full Extra',
            self::CompOff => 'Comp Off',
            self::Ot => 'OT',
        };
    }

    public function isBenefit(): bool
    {
        return $this !== self::NoBenefit;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
