<?php

namespace App\Rules;

use App\Support\Bonvoice\BonvoicePhoneNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates users.bonvoice_extension as a 10-digit mobile used for inbound IVR agent matching.
 */
class ClickToCallMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $normalized = BonvoicePhoneNormalizer::normalizeDialablePhone((string) $value);

        if ($normalized === null) {
            $fail('The :attribute must be a valid 10-digit mobile number.');
        }
    }
}
