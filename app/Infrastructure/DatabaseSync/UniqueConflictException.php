<?php

namespace App\Infrastructure\DatabaseSync;

use RuntimeException;

class UniqueConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $conflict
     */
    public function __construct(
        public readonly array $conflict,
        string $message = 'Business unique key conflict with different primary key.',
    ) {
        parent::__construct($message);
    }
}
