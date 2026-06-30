<?php

namespace App\Services\SerialValidation\Validators;

use App\Data\SerialValidationResult;

class Pb1000SerialValidator extends AbstractProductSerialValidator
{
    public function product(): string
    {
        return 'PB 1000';
    }

    public function validate(string $serial): SerialValidationResult
    {
        $normalized = $this->normalize($serial);

        if ($normalized === '') {
            return $this->invalid($normalized, 'Serial number is required.');
        }

        if (! preg_match('/^[A-Z0-9]+$/', $normalized)) {
            return $this->invalid(
                $normalized,
                'PB 1000 serial numbers must contain letters and numbers only.',
            );
        }

        if (strlen($normalized) !== 12) {
            return $this->invalid(
                $normalized,
                'PB 1000 serial numbers must contain exactly 12 characters.',
            );
        }

        if (! str_starts_with($normalized, 'LN') && ! str_starts_with($normalized, 'LU')) {
            return $this->invalid(
                $normalized,
                'PB 1000 serial numbers must begin with LN or LU.',
            );
        }

        return $this->valid($normalized);
    }
}
