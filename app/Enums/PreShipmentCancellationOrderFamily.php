<?php

namespace App\Enums;

enum PreShipmentCancellationOrderFamily: string
{
    case Rbp = 'rbp';
    case Rde = 'rde';
    case Rdp = 'rdp';
    case Rnp = 'rnp';

    public static function fromSourceId(?string $sourceId): ?self
    {
        $upper = strtoupper(trim((string) $sourceId));
        if (str_starts_with($upper, 'RNP')) {
            return self::Rnp;
        }
        if (str_starts_with($upper, 'RBP')) {
            return self::Rbp;
        }
        if (str_starts_with($upper, 'RDE')) {
            return self::Rde;
        }
        if (str_starts_with($upper, 'RDP')) {
            return self::Rdp;
        }

        return null;
    }
}
