<?php

namespace App\Services\Oem\Morpho\Data;

final class MorphoSerialValidationResult
{
    public function __construct(
        public readonly MorphoOutcome $outcome,
        public readonly string $normalizedSerial,
        public readonly ?string $oemResponseCode = null,
        public readonly ?string $message = null,
    ) {}

    public static function invalidFormat(string $serial, string $message): self
    {
        return new self(
            outcome: MorphoOutcome::Invalid,
            normalizedSerial: $serial,
            message: $message,
        );
    }
}
