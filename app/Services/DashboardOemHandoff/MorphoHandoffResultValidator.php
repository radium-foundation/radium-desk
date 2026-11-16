<?php

namespace App\Services\DashboardOemHandoff;

use App\Support\Orders\OrderSerialOwnership;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MorphoHandoffResultValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validateDraft(string $expectedSerial, array $payload): array
    {
        $outcome = (string) ($payload['outcome'] ?? 'inconclusive');
        $fields = $payload['fields'] ?? [];
        if (! is_array($fields)) {
            throw ValidationException::withMessages(['fields' => ['Morpho result fields must be an array.']]);
        }

        $normalizedSerial = OrderSerialOwnership::normalizeSerial($expectedSerial);
        if ($normalizedSerial === null) {
            throw ValidationException::withMessages(['serial_number' => ['Session serial is invalid.']]);
        }

        $displayedSerial = OrderSerialOwnership::normalizeSerial($this->stringOrNull($fields['serial_number'] ?? null));
        if ($displayedSerial !== null && Str::upper($displayedSerial) !== Str::upper($normalizedSerial)) {
            throw ValidationException::withMessages([
                'fields' => ['Displayed Morpho serial does not match the active Desk session serial.'],
            ]);
        }

        if ($outcome === 'found') {
            $hasSignal = [
                $this->stringOrNull($fields['device_validity'] ?? null),
                $this->stringOrNull($fields['whitelist_status'] ?? null),
                $this->stringOrNull($fields['expiry_date'] ?? null),
                $this->stringOrNull($fields['device_type'] ?? null),
            ];
            if (! array_filter($hasSignal)) {
                throw ValidationException::withMessages(['fields' => ['Morpho result contains no usable fields.']]);
            }
        }

        return [
            'oem' => 'morpho',
            'schema_version' => 1,
            'outcome' => in_array($outcome, ['found', 'not_found', 'inconclusive'], true) ? $outcome : 'inconclusive',
            'serial_number' => $normalizedSerial,
            'capture_method' => 'extension_dom',
            'captured_at' => now()->toIso8601String(),
            'fields' => [
                'serial_number' => $normalizedSerial,
                'device_validity' => $this->stringOrNull($fields['device_validity'] ?? null),
                'whitelist_status' => $this->stringOrNull($fields['whitelist_status'] ?? null),
                'whitelist_date' => $this->parseDate($this->stringOrNull($fields['whitelist_date'] ?? null)),
                'expiry_date' => $this->parseDate($this->stringOrNull($fields['expiry_date'] ?? null)),
                'validity_years' => $this->stringOrNull($fields['validity_years'] ?? null),
                'device_type' => $this->stringOrNull($fields['device_type'] ?? null),
            ],
        ];
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

        throw ValidationException::withMessages([
            'fields' => ['Morpho date field format is not supported.'],
        ]);
    }
}