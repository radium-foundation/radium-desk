<?php

namespace App\Services\RadiumBoxRead;

use RuntimeException;

class RadiumBoxReadDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('RadiumBox read API is disabled.');
    }
}
