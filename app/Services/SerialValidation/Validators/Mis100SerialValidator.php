<?php

namespace App\Services\SerialValidation\Validators;

use App\Data\SerialValidationResult;

class Mis100SerialValidator extends AbstractProductSerialValidator
{
    public function product(): string
    {
        return 'MIS 100';
    }

    public function validate(string $serial): SerialValidationResult
    {
        $normalized = $this->normalize($serial);

        if ($normalized === '') {
            return $this->invalid($normalized, 'Serial number is required.');
        }

        if (! ctype_digit($normalized)) {
            return $this->invalid($normalized, 'MIS 100 serial numbers must be numeric only.');
        }

        $length = strlen($normalized);

        if ($length === 7) {
            return $this->valid($normalized);
        }

        if ($length === 8) {
            if ($normalized[0] !== '1') {
                return $this->invalid(
                    $normalized,
                    'MIS 100 serial numbers with 8 digits must start with 1.',
                );
            }

            return $this->valid($normalized);
        }

        return $this->invalid(
            $normalized,
            'MIS 100 serial numbers must contain exactly 7 or 8 digits.',
        );
    }
}
