<?php

namespace App\Services\StatutoryInvoice;

use Illuminate\Validation\ValidationException;

/**
 * Owner-finalized Delhi / Mumbai statutory series.
 *
 * Number = INV-{GST_STATE}{FY_CODE}{RUNNING_SERIAL}
 * FY 2026-27 Delhi serial 1 = INV-07671. Serial starts at 1 each FY.
 */
final class StatutoryLocationSeries
{
    public const DELHI = 'delhi';

    public const MUMBAI = 'mumbai';

    public function enabled(): bool
    {
        return (bool) config('statutory_invoices.location_series.enabled', false);
    }

    public function resolveFromBranchCode(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }

        foreach ($this->locations() as $location => $config) {
            $codes = array_map(
                static fn (string $mapped): string => strtoupper($mapped),
                $config['branch_codes'],
            );
            if (in_array($code, $codes, true)) {
                return $location;
            }
        }

        return null;
    }

    public function requireFromBranchCode(?string $code): string
    {
        $location = $this->resolveFromBranchCode($code);
        if ($location !== null) {
            return $location;
        }

        throw ValidationException::withMessages([
            'location' => 'Product statutory numbering requires a Delhi or Mumbai branch. Unmapped locations fail closed.',
        ]);
    }

    public function isKnown(string $location): bool
    {
        return isset($this->locations()[$location]);
    }

    public function gstStateCode(string $location): string
    {
        return $this->location($location)['gst_state_code'];
    }

    public function prefix(string $location, StatutoryFinancialYear $year): string
    {
        return 'INV-'.$this->gstStateCode($location).$year->code();
    }

    public function formatNumber(string $location, StatutoryFinancialYear $year, int $seq): string
    {
        if ($seq < 1) {
            throw ValidationException::withMessages([
                'number' => 'Statutory running serial must start at 1.',
            ]);
        }

        return $this->prefix($location, $year).$seq;
    }

    public function sequenceKey(string $location, StatutoryFinancialYear $year): string
    {
        return implode('|', [
            'tax_invoice',
            'location:'.$location,
            $this->prefix($location, $year),
            $year->token(),
        ]);
    }

    /**
     * @return array<string, array{gst_state_code: string, branch_codes: list<string>, gstin: string, address: string, state: string}>
     */
    public function locations(): array
    {
        $raw = config('statutory_invoices.location_series.locations', []);
        if (! is_array($raw)) {
            return [];
        }

        $locations = [];
        foreach ($raw as $key => $config) {
            if (! is_string($key) || ! is_array($config)) {
                continue;
            }
            $state = trim((string) ($config['gst_state_code'] ?? ''));
            $codes = $config['branch_codes'] ?? [];
            if (preg_match('/^\d{2}$/', $state) !== 1 || ! is_array($codes) || $codes === []) {
                continue;
            }
            $locations[$key] = [
                'gst_state_code' => $state,
                'branch_codes' => array_values(array_filter(
                    array_map(static fn ($code): string => trim((string) $code), $codes),
                    static fn (string $code): bool => $code !== '',
                )),
                'gstin' => trim((string) ($config['gstin'] ?? '')),
                'address' => trim((string) ($config['address'] ?? '')),
                'state' => trim((string) ($config['state'] ?? '')),
            ];
        }

        return $locations;
    }

    /**
     * @return array{gst_state_code: string, branch_codes: list<string>, gstin: string, address: string, state: string}
     */
    private function location(string $location): array
    {
        $locations = $this->locations();
        if (! isset($locations[$location])) {
            throw ValidationException::withMessages([
                'location' => 'Unknown statutory numbering location.',
            ]);
        }

        return $locations[$location];
    }
}
