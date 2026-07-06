<?php

namespace App\Services\SerialValidation\Validators;

use App\Data\SerialValidationResult;

class Marc11SerialValidator extends AbstractProductSerialValidator
{
    public function product(): string
    {
        return 'MARC 11';
    }

    public function validate(string $serial): SerialValidationResult
    {
        $normalized = $this->normalize($serial);

        if ($normalized === '') {
            return $this->invalid($normalized, 'Serial number is required.');
        }

        if (! ctype_digit($normalized)) {
            return $this->invalid($normalized, 'MARC 11 serial numbers must be numeric only.');
        }

        $length = strlen($normalized);

        if ($length !== 7 && $length !== 10) {
            return $this->invalid(
                $normalized,
                'MARC 11 serial numbers must contain exactly 7 or 10 digits.',
            );
        }

        $firstDigit = $normalized[0];

        if ($firstDigit === '2' && str_starts_with($normalized, '25')) {
            return $this->warning(
                $normalized,
                'MARC 11 serial numbers with a 25 prefix need review.',
            );
        }

        if ($firstDigit !== '7' && $firstDigit !== '8') {
            return $this->invalid(
                $normalized,
                'MARC 11 serial numbers must start with 7 or 8.',
            );
        }

        return $this->valid($normalized);
    }
}
