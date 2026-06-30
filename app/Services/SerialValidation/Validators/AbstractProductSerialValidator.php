<?php

namespace App\Services\SerialValidation\Validators;

use App\Data\SerialValidationResult;
use App\Services\SerialValidation\Contracts\ProductSerialValidator;

abstract class AbstractProductSerialValidator implements ProductSerialValidator
{
    protected function normalize(string $serial): string
    {
        return strtoupper(trim($serial));
    }

    protected function invalid(string $normalizedSerial, string $reason): SerialValidationResult
    {
        return SerialValidationResult::invalid(
            normalizedSerial: $normalizedSerial,
            product: $this->product(),
            reason: $reason,
        );
    }

    protected function valid(
        string $normalizedSerial,
        bool $corrected = false,
        bool $requiresRadiumBoxVerification = false,
        ?string $reason = null,
    ): SerialValidationResult {
        return SerialValidationResult::valid(
            normalizedSerial: $normalizedSerial,
            product: $this->product(),
            corrected: $corrected,
            requiresRadiumBoxVerification: $requiresRadiumBoxVerification,
            reason: $reason,
        );
    }
}
