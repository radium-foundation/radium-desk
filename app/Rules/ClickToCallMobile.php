<?php

namespace App\Rules;

use App\Services\Bonvoice\BonvoiceClickToCallService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ClickToCallMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $normalized = app(BonvoiceClickToCallService::class)->normalizeDialablePhone((string) $value);

        if ($normalized === null) {
            $fail('The :attribute must be a valid 10-digit mobile number.');
        }
    }
}
