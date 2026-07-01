<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidAutomationPolicyException extends RuntimeException
{
    public static function forKey(string $key, string $reason): self
    {
        return new self("Invalid automation policy [{$key}]: {$reason}");
    }
}
