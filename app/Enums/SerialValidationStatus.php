<?php

namespace App\Enums;

enum SerialValidationStatus: string
{
    case Valid = 'valid';
    case Warning = 'warning';
    case Invalid = 'invalid';
    case Unsupported = 'unsupported';
    case Pending = 'pending';

    public function isValid(): bool
    {
        return $this === self::Valid;
    }

    public function isWarning(): bool
    {
        return $this === self::Warning;
    }

    public function isInvalid(): bool
    {
        return $this === self::Invalid;
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
