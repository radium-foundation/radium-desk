<?php

namespace App\Services\StatutoryInvoice;

use RuntimeException;

/**
 * Retryable service statutory mint failure (infrastructure/transient dependency).
 */
final class ServiceStatutoryInvoiceTemporaryFailureException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $classification = 'transient_failure',
    ) {
        parent::__construct($message);
    }
}
