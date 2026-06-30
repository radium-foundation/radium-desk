<?php

namespace App\Enums;

enum SerialValidationStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unsupported = 'unsupported';

    public function isValid(): bool
    {
        return $this === self::Valid;
    }

    public function isInvalid(): bool
    {
        return $this === self::Invalid;
    }
}
