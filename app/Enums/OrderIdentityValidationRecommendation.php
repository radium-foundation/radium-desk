<?php

namespace App\Enums;

enum OrderIdentityValidationRecommendation: string
{
    case ValidatorTooStrict = 'validator_too_strict';
    case RadiumBoxInvalidIdentity = 'radiumbox_invalid_identity';
    case ProductMappingMismatch = 'product_mapping_mismatch';
    case DuplicateSerialConflict = 'duplicate_serial_conflict';
    case ManualReviewRequired = 'manual_review_required';

    public function label(): string
    {
        return match ($this) {
            self::ValidatorTooStrict => 'Validator probably too strict',
            self::RadiumBoxInvalidIdentity => 'RadiumBox returned invalid identity',
            self::ProductMappingMismatch => 'Product mapping mismatch',
            self::DuplicateSerialConflict => 'Duplicate serial conflict',
            self::ManualReviewRequired => 'Manual review required',
        };
    }

    public function displayLabel(): string
    {
        return '✔ '.$this->label();
    }
}
