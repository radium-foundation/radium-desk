<?php

namespace App\Services\SerialValidation\Validators;

use App\Data\SerialValidationResult;

class MsoE3SerialValidator extends AbstractProductSerialValidator
{
    /** @var list<string> */
    private const REJECTED_PREFIXES = ['17', '18', '19', '21', '22'];

    /** @var list<string> */
    private const WARNING_PREFIXES = ['20'];

    public function product(): string
    {
        return 'MSO E3';
    }

    public function validate(string $serial): SerialValidationResult
    {
        $normalized = $this->normalize($serial);

        if ($normalized === '') {
            return $this->invalid($normalized, 'Serial number is required.');
        }

        if (strlen($normalized) !== 11) {
            return $this->invalid(
                $normalized,
                'MSO E3 serial numbers must contain exactly 11 characters.',
            );
        }

        foreach (self::WARNING_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return $this->warning(
                    $normalized,
                    'MSO E3 serial numbers with prefix '.$prefix.' need review.',
                );
            }
        }

        foreach (self::REJECTED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return $this->invalid(
                    $normalized,
                    'MSO E3 serial numbers cannot begin with '.$prefix.'.',
                );
            }
        }

        $prefix = substr($normalized, 0, 4);
        $fifthCharacter = $normalized[4];
        $suffix = substr($normalized, 5);

        if (! ctype_digit($prefix)) {
            return $this->invalid(
                $normalized,
                'MSO E3 serial numbers must have numeric characters in positions 1 to 4.',
            );
        }

        if (! ctype_digit($suffix)) {
            return $this->invalid(
                $normalized,
                'MSO E3 serial numbers must have numeric characters in positions 6 to 11.',
            );
        }

        $corrected = false;
        $correctedFifthCharacter = $fifthCharacter;

        if (in_array($fifthCharacter, ['L', '1'], true)) {
            $correctedFifthCharacter = 'I';
            $corrected = true;
        }

        if ($correctedFifthCharacter !== 'I') {
            return $this->invalid(
                $normalized,
                'MSO E3 serial numbers must have I as the 5th character.',
            );
        }

        return $this->valid(
            normalizedSerial: $prefix.$correctedFifthCharacter.$suffix,
            corrected: $corrected,
            requiresRadiumBoxVerification: true,
            reason: $corrected ? 'Corrected by IRA' : null,
        );
    }
}
