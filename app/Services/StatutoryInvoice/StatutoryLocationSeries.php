<?php

namespace App\Services\StatutoryInvoice;

use Illuminate\Validation\ValidationException;

/**
 * Owner-finalized Delhi / Mumbai statutory series (2026-09-01).
 *
 * Maps only the documented Desk branch codes. Unmapped locations fail closed.
 * This is not a GSTIN, legal-name, or historical Admin remint table.
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
            'location' => 'Statutory numbering requires a Delhi or Mumbai branch. Unmapped locations fail closed.',
        ]);
    }

    public function prefix(string $location): string
    {
        return $this->location($location)['prefix'];
    }

    public function firstSeq(string $location): int
    {
        return $this->location($location)['first_seq'];
    }

    public function sequenceKey(string $location): string
    {
        $config = $this->location($location);

        return implode('|', [
            'tax_invoice',
            'location:'.$location,
            $config['prefix'],
            '*',
        ]);
    }

    public function formatNumber(string $location, int $seq): string
    {
        return $this->prefix($location).$seq;
    }

    /**
     * @return array<string, array{prefix: string, first_seq: int, branch_codes: list<string>}>
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
            $prefix = trim((string) ($config['prefix'] ?? ''));
            $firstSeq = (int) ($config['first_seq'] ?? 0);
            $codes = $config['branch_codes'] ?? [];
            if ($prefix === '' || $firstSeq < 1 || ! is_array($codes) || $codes === []) {
                continue;
            }
            $locations[$key] = [
                'prefix' => $prefix,
                'first_seq' => $firstSeq,
                'branch_codes' => array_values(array_filter(
                    array_map(static fn ($code): string => trim((string) $code), $codes),
                    static fn (string $code): bool => $code !== '',
                )),
            ];
        }

        return $locations;
    }

    /**
     * @return array{prefix: string, first_seq: int, branch_codes: list<string>}
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
