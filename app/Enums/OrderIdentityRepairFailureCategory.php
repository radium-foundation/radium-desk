<?php

namespace App\Enums;

enum OrderIdentityRepairFailureCategory: string
{
    case RadiumBoxNotFound = 'radiumbox_not_found';
    case ApiTimeout = 'api_timeout';
    case RateLimited = 'rate_limited';
    case DuplicateSerial = 'duplicate_serial';
    case ValidationFailed = 'validation_failed';
    case UnexpectedException = 'unexpected_exception';

    public function label(): string
    {
        return match ($this) {
            self::RadiumBoxNotFound => 'RadiumBox not found',
            self::ApiTimeout => 'API timeout',
            self::RateLimited => 'Rate limited',
            self::DuplicateSerial => 'Duplicate serial',
            self::ValidationFailed => 'Validation failed',
            self::UnexpectedException => 'Unexpected exception',
        };
    }
}
