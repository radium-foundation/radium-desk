<?php

namespace App\Services\StatutoryInvoice;

use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Deterministic NIC address formatting. Preserves full source text; never blind-truncates.
 */
final class StatutoryAddressFormatter
{
    public const ADDR1_MAX = 100;

    public const ADDR2_MAX = 100;

    public const COMBINED_MAX = 200;

    /**
     * @param  array<string, mixed>|null  $structured
     * @return array{
     *     addr1: string,
     *     addr2: string,
     *     combined: string,
     *     exceeds_limit: bool,
     *     source: string
     * }
     */
    public function format(?string $fullAddress, ?array $structured = null): array
    {
        $structuredText = $this->structuredText($structured);
        $full = trim((string) $fullAddress);
        $source = 'full_text';
        $canonical = $full;

        if ($structuredText !== '') {
            $canonical = $structuredText;
            $source = 'structured';
        }

        if ($canonical === '') {
            return [
                'addr1' => '',
                'addr2' => '',
                'combined' => '',
                'exceeds_limit' => false,
                'source' => $source,
            ];
        }

        [$addr1, $addr2] = $this->split($canonical);

        return [
            'addr1' => $addr1,
            'addr2' => $addr2,
            'combined' => trim($addr1.($addr2 !== '' ? ', '.$addr2 : '')),
            'exceeds_limit' => strlen($canonical) > self::COMBINED_MAX,
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structured
     */
    private function structuredText(?array $structured): string
    {
        if ($structured === null) {
            return '';
        }

        $line1 = StatutoryBillingStructured::nullable($structured['line1'] ?? null);
        $line2 = StatutoryBillingStructured::nullable($structured['line2'] ?? null);
        // City/state/PIN are separate NIC Loc/Pin fields; address text is line1+line2 only.
        if ($line1 !== null && $line2 !== null) {
            return trim(rtrim($line1).' '.$line2);
        }

        return trim((string) ($line1 ?? $line2 ?? ''));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $canonical): array
    {
        if (strlen($canonical) <= self::ADDR1_MAX) {
            return [$canonical, ''];
        }

        $break = $this->breakOffset($canonical, self::ADDR1_MAX);
        $addr1 = rtrim(substr($canonical, 0, $break), " \t,");
        $remainder = ltrim(substr($canonical, $break), " \t,");

        if ($addr1 === '') {
            $addr1 = substr($canonical, 0, self::ADDR1_MAX);
            $remainder = ltrim(substr($canonical, self::ADDR1_MAX), " \t,");
        }

        if (strlen($remainder) <= self::ADDR2_MAX) {
            return [$addr1, $remainder];
        }

        $secondBreak = $this->breakOffset($remainder, self::ADDR2_MAX);
        $addr2 = rtrim(substr($remainder, 0, $secondBreak), " \t,");
        if ($addr2 === '') {
            $addr2 = substr($remainder, 0, self::ADDR2_MAX);
        }

        return [$addr1, $addr2];
    }

    private function breakOffset(string $text, int $max): int
    {
        $window = substr($text, 0, $max);
        $comma = strrpos($window, ',');
        if ($comma !== false && $comma >= 40) {
            return $comma + 1;
        }
        $space = strrpos($window, ' ');
        if ($space !== false && $space >= 40) {
            return $space + 1;
        }

        return $max;
    }
}
