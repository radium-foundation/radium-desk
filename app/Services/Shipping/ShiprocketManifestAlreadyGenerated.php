<?php

namespace App\Services\Shipping;

use App\Services\Shipping\Data\ShiprocketDocumentResult;

/**
 * Shiprocket manifest generate duplicate signal (no verified print/reconcile API in Desk).
 */
final class ShiprocketManifestAlreadyGenerated
{
    public const OPERATOR_MESSAGE = 'Shiprocket reports a manifest already exists for this shipment. '
        .'Desk cannot auto-fetch it without a verified print contract. '
        .'Open Shiprocket to confirm or download the manifest; do not keep clicking Generate Manifest.';

    public static function matchesRejectedResult(ShiprocketDocumentResult $result): bool
    {
        if ($result->retryable || $result->status === 'generated') {
            return false;
        }

        return self::matchesErrorText($result->error);
    }

    public static function matchesErrorText(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        $normalized = strtolower($text);

        if (! str_contains($normalized, 'manifest')) {
            return false;
        }

        foreach (['already generated', 'already exists', 'already been generated', 'manifest is already'] as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
