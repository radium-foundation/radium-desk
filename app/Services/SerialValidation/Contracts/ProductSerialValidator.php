<?php

namespace App\Services\SerialValidation\Contracts;

use App\Data\SerialValidationResult;

interface ProductSerialValidator
{
    public function product(): string;

    public function validate(string $serial): SerialValidationResult;
}
