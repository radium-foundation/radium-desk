<?php

namespace App\Enums;

enum OrderIdentityValidationFailureGroup: string
{
    case ValidatorRule = 'validator_rule';
    case DuplicateSerial = 'duplicate_serial';
    case RadiumBoxNotFound = 'radiumbox_not_found';
    case ProductMappingMismatch = 'product_mapping_mismatch';
    case WaitingForCustomerSerial = 'waiting_for_customer_serial';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::ValidatorRule => 'Validator rule',
            self::DuplicateSerial => 'Duplicate serial',
            self::RadiumBoxNotFound => 'RadiumBox not found',
            self::ProductMappingMismatch => 'Product mapping mismatch',
            self::WaitingForCustomerSerial => 'Waiting for customer serial',
            self::Unknown => 'Unknown',
        };
    }
}
