<?php

namespace App\Services\StatutoryInvoice;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

final class ServiceStatutoryInvoiceMintFailureClassifier
{
    public function toRetryableException(Throwable $exception): Throwable
    {
        if ($exception instanceof ServiceStatutoryInvoicePermanentFailureException
            || $exception instanceof ServiceStatutoryInvoiceTemporaryFailureException) {
            return $exception;
        }

        if ($exception instanceof ValidationException) {
            return new ServiceStatutoryInvoicePermanentFailureException(
                message: $this->flattenValidationMessage($exception),
                classification: 'validation_failure',
            );
        }

        if ($this->isTransient($exception)) {
            return new ServiceStatutoryInvoiceTemporaryFailureException(
                message: $exception->getMessage(),
                classification: 'transient_failure',
            );
        }

        return new ServiceStatutoryInvoicePermanentFailureException(
            message: $exception->getMessage(),
            classification: 'unclassified_failure',
        );
    }

    public function isPermanent(Throwable $exception): bool
    {
        $classified = $this->toRetryableException($exception);

        return $classified instanceof ServiceStatutoryInvoicePermanentFailureException;
    }

    private function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || str_contains($message, 'connection')
            || str_contains($message, 'server has gone away')
            || str_contains($message, 'too many connections');
    }

    private function flattenValidationMessage(ValidationException $exception): string
    {
        $messages = [];
        foreach ($exception->errors() as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                $messages[] = (string) $message;
            }
        }

        return $messages !== [] ? implode(' ', array_unique($messages)) : $exception->getMessage();
    }
}
