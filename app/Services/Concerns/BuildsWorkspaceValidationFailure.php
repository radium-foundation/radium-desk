<?php

namespace App\Services\Concerns;

use Illuminate\Validation\ValidationException;

trait BuildsWorkspaceValidationFailure
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    protected function firstValidationMessage(array $errors): string
    {
        foreach ($errors as $fieldErrors) {
            if (is_array($fieldErrors) && $fieldErrors !== []) {
                return (string) $fieldErrors[0];
            }
        }

        return 'The given data was invalid.';
    }

    protected function firstValidationMessageFromException(ValidationException $exception): string
    {
        return $this->firstValidationMessage($exception->errors());
    }
}
