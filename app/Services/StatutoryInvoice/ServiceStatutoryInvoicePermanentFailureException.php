<?php

namespace App\Services\StatutoryInvoice;

use RuntimeException;

/**
 * Non-retryable service statutory mint failure (eligibility, validation, missing data).
 */
final class ServiceStatutoryInvoicePermanentFailureException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $classification = 'permanent_business_failure',
    ) {
        parent::__construct($message);
    }
}
