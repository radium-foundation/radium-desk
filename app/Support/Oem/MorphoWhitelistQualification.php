<?php

namespace App\Support\Oem;

/**
 * Strict DOM/API whitelist classifier for Morpho handoff capture (extension_dom only).
 */
final class MorphoWhitelistQualification
{
    public const ALREADY_WHITELISTED = 'already_whitelisted';

    public const VALID_NOT_WHITELISTED = 'valid_not_whitelisted';

    public const INVALID_DEVICE = 'invalid_device';

    public const AMBIGUOUS = 'ambiguous';

    public const INCONCLUSIVE = 'inconclusive';

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function classify(string $outcome, array $fields): string
    {
        $deviceValidity = self::normalizeText($fields['device_validity'] ?? null);
        $whitelistStatus = self::normalizeText($fields['whitelist_status'] ?? null);

        if ($outcome === 'not_found' || self::isInvalidDeviceValidity($deviceValidity)) {
            return self::INVALID_DEVICE;
        }

        if ($whitelistStatus === null && $deviceValidity === null) {
            return self::INCONCLUSIVE;
        }

        if ($whitelistStatus !== null && self::isExplicitNotWhitelisted($whitelistStatus)) {
            return self::VALID_NOT_WHITELISTED;
        }

        if ($whitelistStatus !== null && self::isExplicitAlreadyWhitelisted($whitelistStatus)) {
            if ($deviceValidity !== null && self::isInvalidDeviceValidity($deviceValidity)) {
                return self::AMBIGUOUS;
            }

            return self::ALREADY_WHITELISTED;
        }

        if ($deviceValidity !== null && self::isValidDeviceValidity($deviceValidity) && $whitelistStatus === null) {
            return self::VALID_NOT_WHITELISTED;
        }

        if ($whitelistStatus !== null || $deviceValidity !== null) {
            return self::AMBIGUOUS;
        }

        return self::INCONCLUSIVE;
    }

    public static function isPopulationEligible(string $qualification, string $outcome): bool
    {
        return $outcome === 'found' && $qualification === self::ALREADY_WHITELISTED;
    }

    private static function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function isInvalidDeviceValidity(?string $deviceValidity): bool
    {
        if ($deviceValidity === null) {
            return false;
        }

        $lower = strtolower($deviceValidity);

        return str_contains($lower, 'invalid')
            || str_contains($lower, 'not valid')
            || str_contains($lower, 'does not exist');
    }

    private static function isValidDeviceValidity(?string $deviceValidity): bool
    {
        if ($deviceValidity === null) {
            return false;
        }

        $lower = strtolower($deviceValidity);

        if (self::isInvalidDeviceValidity($deviceValidity)) {
            return false;
        }

        return str_contains($lower, 'valid');
    }

    private static function isExplicitAlreadyWhitelisted(string $whitelistStatus): bool
    {
        $lower = strtolower($whitelistStatus);

        if (preg_match('/\balready\s+whitelisted\b/', $lower) === 1) {
            return true;
        }

        if (preg_match('/\bdevice\s+already\s+whitelisted\b/', $lower) === 1) {
            return true;
        }

        return preg_match('/^\s*whitelisted\s*$/', $lower) === 1;
    }

    private static function isExplicitNotWhitelisted(string $whitelistStatus): bool
    {
        $lower = strtolower($whitelistStatus);

        if (preg_match('/\bnot\s+whitelisted\b/', $lower) === 1) {
            return true;
        }

        if (preg_match('/\bvalid\b.*\bnot\s+whitelisted\b/', $lower) === 1) {
            return true;
        }

        return preg_match('/\bnot\s+whitelisted\b.*\bvalid\b/', $lower) === 1;
    }
}