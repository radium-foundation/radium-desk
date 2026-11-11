<?php

namespace App\Services\OemWarranty;

use App\Enums\OemWarrantyCaptureMethod;
use App\Enums\OemWarrantyLookupOutcome;
use App\Enums\OemWarrantyProvider;
use App\Support\Orders\OrderSerialOwnership;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OemWarrantyResultValidator
{
    public function __construct(
        private readonly OemWarrantyDateParser $dateParser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validateDraft(
        OemWarrantyProvider $provider,
        string $expectedSerial,
        OemWarrantyCaptureMethod $captureMethod,
        array $payload,
    ): array {
        $outcome = OemWarrantyLookupOutcome::tryFrom((string) ($payload['outcome'] ?? ''))
            ?? OemWarrantyLookupOutcome::Inconclusive;

        $rows = $payload['rows'] ?? [];
        if (! is_array($rows)) {
            throw ValidationException::withMessages(['rows' => 'Results must be an array.']);
        }

        $normalizedSerial = OrderSerialOwnership::normalizeSerial($expectedSerial);
        if ($normalizedSerial === null) {
            throw ValidationException::withMessages(['serial_number' => 'Order serial is missing or invalid.']);
        }

        if ($outcome === OemWarrantyLookupOutcome::Found && $rows === []) {
            throw ValidationException::withMessages(['rows' => 'Found outcome requires at least one result row.']);
        }

        $primary = $this->selectPrimaryRow($provider, $rows, $normalizedSerial);

        if ($outcome === OemWarrantyLookupOutcome::Found) {
            $rowSerial = OrderSerialOwnership::normalizeSerial($this->stringOrNull($primary['serial_number'] ?? $primary['SerialNo'] ?? $primary['serialNumber'] ?? null));
            if ($rowSerial !== null && Str::upper($rowSerial) !== Str::upper($normalizedSerial)) {
                throw ValidationException::withMessages([
                    'rows' => 'Result serial does not match the order serial being checked.',
                ]);
            }
        }

        return [
            'provider' => $provider,
            'serial_number' => $normalizedSerial,
            'outcome' => $outcome,
            'capture_method' => $captureMethod,
            'provider_payload' => [
                'rows' => $rows,
                'raw' => $payload['provider_raw'] ?? null,
            ],
            'warranty_expiry_date' => $this->dateParser->parse($this->stringOrNull($primary['warranty_expiry_date'] ?? $primary['WarrantyExpiryDate'] ?? null)),
            'rd_expiry_date' => $this->dateParser->parse($this->stringOrNull($primary['rd_expiry_date'] ?? $primary['RdExpiryDate'] ?? null)),
            'mantratec_model' => $this->stringOrNull($primary['model'] ?? $primary['Model'] ?? null),
            'morpho_order_number' => $this->stringOrNull($primary['order_number'] ?? $primary['orderNumber'] ?? null),
            'morpho_whitelisting_date' => $this->dateParser->parse($this->stringOrNull($primary['whitelisting_date'] ?? null)),
            'morpho_expiry_date' => $this->dateParser->parse($this->stringOrNull($primary['expiry_date'] ?? null)),
            'morpho_validity_years' => $this->stringOrNull($primary['validity_years'] ?? null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function selectPrimaryRow(OemWarrantyProvider $provider, array $rows, string $expectedSerial): array
    {
        if ($rows === []) {
            return [];
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $serial = OrderSerialOwnership::normalizeSerial($this->stringOrNull($row['serial_number'] ?? $row['SerialNo'] ?? $row['serialNumber'] ?? null));
            if ($serial !== null && Str::upper($serial) === Str::upper($expectedSerial)) {
                return $row;
            }
        }

        if ($provider === OemWarrantyProvider::Mantratec) {
            foreach ($rows as $row) {
                if (is_array($row) && ($row['is_parent'] ?? $row['IsParent'] ?? false)) {
                    return $row;
                }
            }
        }

        return is_array($rows[0]) ? $rows[0] : [];
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
