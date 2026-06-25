<?php

namespace App\Enums;

enum WorkspaceComponent: string
{
    case Assign = 'assign';
    case Remark = 'remark';
    case Resolve = 'resolve';
    case Close = 'close';
    case Timeline = 'timeline';

    public function view(): string
    {
        return match ($this) {
            self::Assign => 'service-cases.fragments.assign-form',
            self::Remark => 'service-cases.fragments.remark-form',
            self::Resolve => 'service-cases.fragments.resolve-form',
            self::Close => 'service-cases.fragments.close-form',
            self::Timeline => 'incidents.partials.activity-timeline',
        };
    }
}
