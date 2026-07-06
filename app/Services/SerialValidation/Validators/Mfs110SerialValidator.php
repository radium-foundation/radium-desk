<?php

namespace App\Services\SerialValidation\Validators;

use App\Data\SerialValidationResult;

class Mfs110SerialValidator extends AbstractProductSerialValidator
{
    public function product(): string
    {
        return 'MFS 110';
    }

    public function validate(string $serial): SerialValidationResult
    {
        $normalized = $this->normalize($serial);

        if ($normalized === '') {
            return $this->invalid($normalized, 'Serial number is required.');
        }

        $clearFailureReason = $this->clearlyInvalidReason($normalized);

        if ($clearFailureReason !== null) {
            return $this->invalid($normalized, $clearFailureReason);
        }

        if (! ctype_digit($normalized)) {
            return $this->invalid($normalized, 'MFS 110 serial numbers must be numeric only.');
        }

        $length = strlen($normalized);

        if ($length === 7) {
            $firstDigit = (int) $normalized[0];

            if ($firstDigit < 6 || $firstDigit > 9) {
                return $this->invalid(
                    $normalized,
                    'MFS 110 serial numbers with 7 digits must start with 6, 7, 8, or 9.',
                );
            }

            return $this->valid($normalized);
        }

        if ($length === 8) {
            if ($normalized[0] !== '1') {
                return $this->invalid(
                    $normalized,
                    'MFS 110 serial numbers with 8 digits must start with 1.',
                );
            }

            return $this->valid($normalized);
        }

        return $this->invalid(
            $normalized,
            'MFS 110 serial numbers must contain exactly 7 or 8 digits.',
        );
    }

    private function clearlyInvalidReason(string $normalized): ?string
    {
        if (str_starts_with($normalized, '54SAXX')) {
            return 'MFS 110 serial numbers cannot be product labels.';
        }

        if ($normalized === 'KAMAL') {
            return 'MFS 110 serial numbers cannot be placeholder text.';
        }

        if (preg_match('/\d+\s*V(?:DC|AC)/i', $normalized) !== 0
            || str_contains($normalized, 'VDC')
            || str_contains($normalized, 'VAC')) {
            return 'MFS 110 serial numbers cannot be voltage specifications.';
        }

        if (str_starts_with($normalized, 'P/N')
            || str_starts_with($normalized, 'PFSPL')
            || str_contains($normalized, 'FPSPL')) {
            return 'MFS 110 serial numbers cannot be part numbers.';
        }

        return null;
    }
}
