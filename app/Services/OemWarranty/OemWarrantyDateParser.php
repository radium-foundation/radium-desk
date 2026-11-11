<?php

namespace App\Services\OemWarranty;

use Carbon\Carbon;
use Illuminate\Support\Str;

final class OemWarrantyDateParser
{
    public function parse(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '' || $this->isPlaceholder($normalized)) {
            return null;
        }

        try {
            return Carbon::parse($normalized)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isPlaceholder(string $value): bool
    {
        $lower = Str::lower($value);

        return in_array($lower, ['n/a', 'na', '-', '—', 'null', 'undefined'], true);
    }
}
