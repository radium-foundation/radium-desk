<?php

namespace App\Services\RadiumBoxRead;

use RuntimeException;

class RadiumBoxReadMisconfiguredException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('RadiumBox read API is not configured: '.$reason);
    }
}
