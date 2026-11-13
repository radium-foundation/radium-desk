<?php

namespace App\Services\Oem\Morpho\Data;

/**
 * Normalized outcomes for Morpho OTBS adapter operations.
 * Does not represent OEM "warranty" unless contractually defined elsewhere.
 */
enum MorphoOutcome: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Registered = 'registered';
    case Whitelisted = 'whitelisted';
    case NotRegistered = 'not_registered';
    case NotWhitelisted = 'not_whitelisted';
    case ExpiryAvailable = 'expiry_available';
    case ExpiryMissing = 'expiry_missing';
    case Unsupported = 'unsupported';
    case AuthenticationUnavailable = 'authentication_unavailable';
    case ServiceUnavailable = 'service_unavailable';
    case UnknownError = 'unknown_error';
}
