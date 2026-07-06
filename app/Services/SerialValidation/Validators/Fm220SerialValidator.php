<?php

namespace App\Services\SerialValidation\Validators;

use App\Data\SerialValidationResult;

class Fm220SerialValidator extends AbstractProductSerialValidator
{
    public function product(): string
    {
        return 'FM 220';
    }

    public function validate(string $serial): SerialValidationResult
    {
        $normalized = $this->normalize($serial);

        if ($normalized === '') {
            return $this->invalid($normalized, 'Serial number is required.');
        }

        if ($this->isKnownManufacturerWarningPattern($normalized)) {
            return $this->warning(
                $normalized,
                'FM 220 serial uses a manufacturer format that needs review.',
            );
        }

        if (! preg_match('/^[A-Z0-9]+$/', $normalized)) {
            return $this->invalid(
                $normalized,
                'FM 220 serial numbers must contain letters and numbers only.',
            );
        }

        if (strlen($normalized) !== 10) {
            return $this->invalid(
                $normalized,
                'FM 220 serial numbers must contain exactly 10 characters.',
            );
        }

        $firstCharacter = $normalized[0];

        if ($firstCharacter !== 'M' && $firstCharacter !== 'P') {
            return $this->invalid(
                $normalized,
                'FM 220 serial numbers must begin with M or P.',
            );
        }

        $modelCode = (int) substr($normalized, 1, 2);

        if ($modelCode < 22 || $modelCode > 26) {
            return $this->invalid(
                $normalized,
                'FM 220 serial numbers must have 22, 23, 24, 25, or 26 as characters 2 and 3.',
            );
        }

        return $this->valid($normalized);
    }

    private function isKnownManufacturerWarningPattern(string $normalized): bool
    {
        if (strlen($normalized) !== 9) {
            return false;
        }

        return str_starts_with($normalized, 'B47')
            || str_starts_with($normalized, 'N01');
    }
}
