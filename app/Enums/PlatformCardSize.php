<?php

namespace App\Enums;

enum PlatformCardSize: string
{
    case XSmall = 'xsmall';
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case Full = 'full';

    public function columnClass(): string
    {
        return match ($this) {
            self::XSmall => 'col-6 col-md-4 col-xl-2',
            self::Small => 'col-12 col-md-6 col-xl-3',
            self::Medium => 'col-12 col-md-6 col-xl-4',
            self::Large => 'col-12 col-lg-8 col-xl-6',
            self::Full => 'col-12',
        };
    }
}
