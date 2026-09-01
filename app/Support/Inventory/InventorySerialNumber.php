<?php

namespace App\Support\Inventory;

final class InventorySerialNumber
{
    public static function normalize(string $serial): string
    {
        return strtoupper(trim($serial));
    }

    /**
     * @param  list<string>|string  $serials
     * @return list<string>
     */
    public static function parseList(array|string $serials): array
    {
        if (is_string($serials)) {
            $serials = preg_split('/[\s,;]+/', $serials) ?: [];
        }

        $normalized = [];
        foreach ($serials as $serial) {
            if (! is_string($serial)) {
                continue;
            }

            $value = self::normalize($serial);
            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }
}
