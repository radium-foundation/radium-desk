<?php

namespace App\Enums;

enum InventoryAdjustmentReason: string
{
    case CountCorrection = 'count_correction';
    case Damage = 'damage';
    case WriteOff = 'write_off';
    case Found = 'found';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CountCorrection => 'Count correction',
            self::Damage => 'Damage',
            self::WriteOff => 'Write-off',
            self::Found => 'Found stock',
            self::Other => 'Other',
        };
    }
}
