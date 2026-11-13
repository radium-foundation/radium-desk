<?php

namespace App\Services\DashboardOemHandoff;

use App\Support\Orders\OrderSerialOwnership;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MantraHandoffResultValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validateDraft(string $expectedSerial, array $payload): array
    {
        $outcome = (string) ($payload['outcome'] ?? 'inconclusive');
        $rows = $payload['rows'] ?? [];
        if (! is_array($rows)) {
            throw ValidationException::withMessages(['rows' => ['Results must be an array.']]);
        }

        $normalizedSerial = OrderSerialOwnership::normalizeSerial($expectedSerial);
        if ($normalizedSerial === null) {
            throw ValidationException::withMessages(['serial_number' => ['Session serial is invalid.']]);
        }

        if ($outcome === 'found' && $rows === []) {
            throw ValidationException::withMessages(['rows' => ['Found outcome requires at least one result row.']]);
        }

        if ($outcome === 'not_found') {
            return [
                'oem' => 'mantra',
                'outcome' => 'not_found',
                'serial_number' => $normalizedSerial,
                'capture_method' => 'extension_dom',
                'rows' => [],
                'warranty_expiry_date' => null,
                'rd_expiry_date' => null,
                'model' => null,
                'captured_at' => now()->toIso8601String(),
            ];
        }

        $primary = $this->selectPrimaryRow($rows, $normalizedSerial);
        if ($primary === []) {
            if ($outcome === 'found') {
                throw ValidationException::withMessages(['rows' => ['No supported Mantra result row was found.']]);
            }

            return [
                'oem' => 'mantra',
                'outcome' => 'inconclusive',
                'serial_number' => $normalizedSerial,
                'capture_method' => 'extension_dom',
                'rows' => [],
                'warranty_expiry_date' => null,
                'rd_expiry_date' => null,
                'model' => null,
                'captured_at' => now()->toIso8601String(),
            ];
        }

        $rowSerial = OrderSerialOwnership::normalizeSerial($this->stringOrNull($primary['serial_number'] ?? null));
        if ($rowSerial === null || Str::upper($rowSerial) !== Str::upper($normalizedSerial)) {
            throw ValidationException::withMessages([
                'rows' => ['Displayed Mantra serial does not match the active Desk session serial.'],
            ]);
        }

        $warrantyExpiry = $this->parseDate($this->stringOrNull($primary['warranty_expiry_date'] ?? null));
        $rdExpiry = $this->parseDate($this->stringOrNull($primary['rd_expiry_date'] ?? null));
        $model = $this->stringOrNull($primary['model'] ?? null);

        if ($outcome === 'found' && $warrantyExpiry === null && $rdExpiry === null && $model === null) {
            throw ValidationException::withMessages(['rows' => ['Mantra result row contains no usable fields.']]);
        }

        return [
            'oem' => 'mantra',
            'outcome' => $outcome === 'found' ? 'found' : 'inconclusive',
            'serial_number' => $normalizedSerial,
            'capture_method' => 'extension_dom',
            'rows' => $rows,
            'warranty_expiry_date' => $warrantyExpiry,
            'rd_expiry_date' => $rdExpiry,
            'model' => $model,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function selectPrimaryRow(array $rows, string $expectedSerial): array
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $serial = OrderSerialOwnership::normalizeSerial($this->stringOrNull($row['serial_number'] ?? null));
            if ($serial !== null && Str::upper($serial) === Str::upper($expectedSerial)) {
                return $row;
            }
        }

        return [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value) === 1) {
            [$d, $m, $y] = explode('/', $value);

            return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
        }

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $value) === 1) {
            [$d, $m, $y] = explode('-', $value);

            return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
        }

        throw ValidationException::withMessages([
            'rows' => ['Mantra date field format is not supported.'],
        ]);
    }
}
