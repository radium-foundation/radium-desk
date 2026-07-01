<?php

namespace App\Exceptions;

use RuntimeException;

class ActiveWaitingStateExistsException extends RuntimeException
{
    public static function forIncident(int $incidentId): self
    {
        return new self("Incident {$incidentId} already has an active waiting state.");
    }
}
