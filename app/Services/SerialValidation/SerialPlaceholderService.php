<?php

namespace App\Services\SerialValidation;

class SerialPlaceholderService
{
    public function isPlaceholder(?string $serial): bool
    {
        if ($serial === null || trim($serial) === '') {
            return true;
        }

        $normalized = $this->normalize($serial);

        if ($normalized === null) {
            return true;
        }

        /** @var list<string> $values */
        $values = config('serial_validation.placeholder_values', []);

        if (in_array($normalized, array_map('strtoupper', $values), true)) {
            return true;
        }

        /** @var list<string> $prefixes */
        $prefixes = config('serial_validation.placeholder_prefixes', []);

        foreach ($prefixes as $prefix) {
            if (str_starts_with($normalized, strtoupper(trim($prefix)))) {
                return true;
            }
        }

        return false;
    }

    public function normalize(?string $serial): ?string
    {
        if ($serial === null) {
            return null;
        }

        $trimmed = trim($serial);

        if ($trimmed === '') {
            return null;
        }

        return strtoupper($trimmed);
    }
}
