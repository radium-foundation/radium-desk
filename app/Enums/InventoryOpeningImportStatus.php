<?php

namespace App\Enums;

enum InventoryOpeningImportStatus: string
{
    case Previewed = 'previewed';
    case Blocked = 'blocked';
    case Applied = 'applied';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Previewed => 'Previewed',
            self::Blocked => 'Blocked',
            self::Applied => 'Applied',
            self::Failed => 'Failed',
        };
    }
}
