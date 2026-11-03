<?php

namespace App\Support\StatutoryInvoice;

use Illuminate\Validation\ValidationException;

/**
 * Canonical billing flat + structured snapshots for ingest and statutory mint.
 */
final class StatutoryBillingAddressComposer
{
    /**
     * @return array{flat: ?string, structured: ?array<string, string>}
     */
    public function compose(?string $rawFlat, mixed $rawStructured): array
    {
        $structured = $this->structuredFromRaw($rawFlat, $rawStructured);

        if ($structured !== null) {
            $structured = StatutoryBillingStructured::dropRedundantLine2($structured);
            $flat = StatutoryBillingStructured::flattenForPersistence($structured, $this->countryFromRaw($rawStructured));

            return $this->finalize($flat, $structured);
        }

        $flat = StatutoryBillingStructured::nullable($rawFlat);

        return $this->finalize($flat, null);
    }

    /**
     * @return array{flat: ?string, structured: ?array<string, string>}
     */
    public function composeFromRaw(mixed $rawBilling): array
    {
        if (is_string($rawBilling)) {
            return $this->compose($rawBilling, null);
        }

        if (is_array($rawBilling)) {
            return $this->compose(null, $rawBilling);
        }

        return $this->finalize(null, null);
    }

    /**
     * @param  array<string, string>|null  $structured
     * @return array{flat: ?string, structured: ?array<string, string>}
     */
    private function finalize(?string $flat, ?array $structured): array
    {
        $this->assertPersistable($flat);

        return [
            'flat' => $flat,
            'structured' => $structured,
        ];
    }

    public function assertPersistable(?string $flat): void
    {
        if ($flat === null) {
            return;
        }

        $max = (int) config('statutory_invoices.billing_address_max_length', 65535);
        if (strlen($flat) > $max) {
            throw ValidationException::withMessages([
                'billing_address' => 'Billing address exceeds the maximum supported length.',
            ]);
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function structuredFromRaw(?string $rawFlat, mixed $rawStructured): ?array
    {
        $fromStructured = StatutoryBillingStructured::fromStored($rawStructured);
        if ($fromStructured !== null) {
            return $fromStructured;
        }

        if (! is_array($rawStructured)) {
            return null;
        }

        return StatutoryBillingStructured::fromParts(
            StatutoryBillingStructured::nullable($rawStructured['line1'] ?? null),
            StatutoryBillingStructured::nullable($rawStructured['city'] ?? null),
            StatutoryBillingStructured::nullable($rawStructured['state'] ?? null),
            StatutoryBillingStructured::nullable($rawStructured['pincode'] ?? null),
            StatutoryBillingStructured::nullable($rawStructured['line2'] ?? null),
        );
    }

    private function countryFromRaw(mixed $rawStructured): ?string
    {
        if (! is_array($rawStructured)) {
            return null;
        }

        return StatutoryBillingStructured::nullable($rawStructured['country'] ?? null);
    }
}
