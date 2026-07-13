<?php

namespace App\Services;

use Illuminate\Support\Str;

class DeviceModelAliasNormalizer
{
    /**
     * Normalize a device model alias for identity lookup.
     *
     * Steps: trim, collapse whitespace, remove separators, lowercase.
     */
    public function normalize(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        $collapsed = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;
        $withoutSeparators = preg_replace('/[\s\-_\.]+/u', '', $collapsed) ?? $collapsed;

        return Str::lower($withoutSeparators);
    }
}
