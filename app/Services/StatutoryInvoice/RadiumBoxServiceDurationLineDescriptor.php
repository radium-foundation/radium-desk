<?php

namespace App\Services\StatutoryInvoice;

/**
 * Invoice descriptions for purchased RadiumBox RD-service duration options.
 */
final class RadiumBoxServiceDurationLineDescriptor
{
    public static function description(?string $durationType): string
    {
        $type = strtolower(trim((string) $durationType));

        return match ($type) {
            'express' => 'RD Technical Support — priority assistance',
            'regular' => 'RD Technical Support — standard assistance',
            default => 'RD Technical Support — purchased assistance',
        };
    }

    public static function variant(?string $durationType): ?string
    {
        $type = strtolower(trim((string) $durationType));

        return in_array($type, ['express', 'regular'], true) ? $type : null;
    }
}
