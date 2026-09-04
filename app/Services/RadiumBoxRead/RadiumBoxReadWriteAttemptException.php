<?php

namespace App\Services\RadiumBoxRead;

use RuntimeException;

class RadiumBoxReadWriteAttemptException extends RuntimeException
{
    public function __construct(string $sql)
    {
        parent::__construct('Write SQL is not allowed on the RadiumBox read connection.');
        $this->sql = $sql;
    }

    public readonly string $sql;
}
