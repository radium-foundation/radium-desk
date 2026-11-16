<?php

namespace App\Services\DashboardOemHandoff;

use App\Support\Oem\OemWarrantyCheckVendor;

/**
 * Maps validated OEM handoff payloads to the single RD subscription expiry date stored on service references.
 */
final class OemHandoffSubscriptionExpiryExtractor
{
    /**
     * @param  array<string, mixed>  $confirmedPayload
     */
    public function extract(string $oem, array $confirmedPayload): ?string
    {
        return match ($oem) {
            OemWarrantyCheckVendor::Mantra->value => $this->stringOrNull($confirmedPayload['rd_expiry_date'] ?? null),
            OemWarrantyCheckVendor::Morpho->value => $this->extractMorpho($confirmedPayload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $confirmedPayload
     */
    private function extractMorpho(array $confirmedPayload): ?string
    {
        $fields = $confirmedPayload['fields'] ?? null;

        if (! is_array($fields)) {
            return null;
        }

        return $this->stringOrNull($fields['expiry_date'] ?? null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
