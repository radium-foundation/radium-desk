<?php

namespace App\Exceptions;

use RuntimeException;

class UnknownAutomationPolicyException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Unknown automation policy [{$key}].");
    }
}
