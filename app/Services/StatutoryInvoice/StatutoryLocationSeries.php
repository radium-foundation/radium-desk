<?php

namespace App\Services\StatutoryInvoice;

use Illuminate\Validation\ValidationException;

/**
 * Owner-finalized Delhi / Mumbai statutory series.
 *
 * Location series (products + Delhi B2B + all Mumbai):
 *   Number = INV-{GST_STATE}{FY_CODE}{RUNNING_SERIAL}
 *   FY 2026-27 Delhi serial 1 = INV-07671. Mumbai serial 1 = INV-27671.
 *
 * Isolated Delhi B2C service series (FY 2026-27 only):
 *   Number = INV-{FY_CODE}{RUNNING_SERIAL}
 *   FY 2026-27 serial 1 = INV-671. Sequence key is location:delhi_b2c.
 *   FY 2027-28 Delhi B2C numbering is UNKNOWN and fails closed.
 *
 * Serial starts at 1 each FY. Do not share the Delhi B2C sequence with Delhi B2B.
 */
final class StatutoryLocationSeries
{
    public const DELHI = 'delhi';

    public const MUMBAI = 'mumbai';

    public const DELHI_B2C = 'delhi_b2c';

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
        return $location === self::DELHI_B2C || isset($this->locations()[$location]);
    }

    /**
     * Seller GST registration for a numbering location.
     *
     * Delhi B2C reuses the Delhi seller GSTIN/address. It does not create a
     * third registration and does not share the Delhi B2B sequence.
     */
    public function sellerLocation(string $location): string
    {
        return $location === self::DELHI_B2C ? self::DELHI : $location;
    }

    public function gstStateCode(string $location): string
    {
        return $this->location($this->sellerLocation($location))['gst_state_code'];
    }

    public function prefix(string $location, StatutoryFinancialYear $year): string
    {
        if ($location === self::DELHI_B2C) {
            $this->assertDelhiB2cYearIsKnown($year);

            return 'INV-'.$year->code();
        }

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
     * @return array<string, array{gst_state_code: string, branch_codes: list<string>, gstin: string, address: string, state: string, pin: string, loc: string}>
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
                'pin' => trim((string) ($config['pin'] ?? '')),
                'loc' => trim((string) ($config['loc'] ?? '')),
            ];
        }

        return $locations;
    }

    /**
     * @return array{gst_state_code: string, branch_codes: list<string>, gstin: string, address: string, state: string, pin: string, loc: string}
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

    private function assertDelhiB2cYearIsKnown(StatutoryFinancialYear $year): void
    {
        if ($year->token() !== '2026-2027') {
            throw ValidationException::withMessages([
                'number' => 'FY '.$year->token().' Delhi B2C numbering is UNKNOWN. Issuance fails closed.',
            ]);
        }
    }
}
