<?php

namespace App\Services\DashboardOemHandoff;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Owner format: AW-DD-MM-YY-NN (e.g. AW-20-01-27-01).
 */
final class RdExpiryServiceReferenceFormatter
{
    public const PREFIX = 'AW';

    public function formatFromIsoDate(string $isoDate, int $sequence): string
    {
        $date = CarbonImmutable::parse($isoDate);

        return $this->format($date, $sequence);
    }

    public function format(CarbonImmutable $expiryDate, int $sequence): string
    {
        if ($sequence < 1 || $sequence > 99_999) {
            throw ValidationException::withMessages([
                'sequence' => 'Service reference sequence is out of range.',
            ]);
        }

        $dd = $expiryDate->format('d');
        $mm = $expiryDate->format('m');
        $yy = $expiryDate->format('y');
        $nn = str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);

        return sprintf('%s-%s-%s-%s-%s', self::PREFIX, $dd, $mm, $yy, $nn);
    }

    /**
     * @return array{dd: string, mm: string, yy: string, nn: string}|null
     */
    public function parseCanonicalReference(string $reference): ?array
    {
        $normalized = trim($reference);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^AW-(\d{2})-(\d{2})-(\d{2})-(\d{2,5})$/', $normalized, $matches) !== 1) {
            return null;
        }

        return [
            'dd' => $matches[1],
            'mm' => $matches[2],
            'yy' => $matches[3],
            'nn' => $matches[4],
        ];
    }

    public function datePrefixFromIsoDate(string $isoDate): string
    {
        $date = CarbonImmutable::parse($isoDate);

        return sprintf(
            '%s-%s-%s-%s-',
            self::PREFIX,
            $date->format('d'),
            $date->format('m'),
            $date->format('y'),
        );
    }
}