<?php

namespace App\Services\RadiumBox;

use Exception;

final class RadiumBoxCatalogAvailabilitySyncException extends Exception
{
    public function __construct(
        string $message,
        public readonly bool $retriable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
