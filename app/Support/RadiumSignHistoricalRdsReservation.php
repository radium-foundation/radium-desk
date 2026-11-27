<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Exact historical RadiumSign RDS* reservation manifest (P-26-57).
 * Non-reserved numeric RDS{n} identifiers are not defined here.
 */
final class RadiumSignHistoricalRdsReservation
{
    private const MANIFEST_PATH = 'data/radiumsign-historical-rds-reservation.json';

    /** @var array<string, true>|null */
    private static ?array $reservedIndex = null;

    private static ?string $manifestSha256 = null;

    public static function manifestPath(): string
    {
        return base_path(self::MANIFEST_PATH);
    }

    public static function manifestSha256(): string
    {
        self::bootIndex();

        return self::$manifestSha256 ?? '';
    }

    public static function isReserved(?string $orderId): bool
    {
        if (! is_string($orderId) || trim($orderId) === '') {
            return false;
        }

        $normalized = strtoupper(trim($orderId));
        self::bootIndex();

        return isset(self::$reservedIndex[$normalized]);
    }

    /**
     * @return list<string>
     */
    public static function reservedIds(): array
    {
        self::bootIndex();

        return array_keys(self::$reservedIndex ?? []);
    }

    private static function bootIndex(): void
    {
        if (self::$reservedIndex !== null) {
            return;
        }

        $path = self::manifestPath();
        if (! File::exists($path)) {
            throw new InvalidArgumentException('Historical RDS reservation manifest is missing.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ($decoded['immutable'] ?? false) !== true) {
            throw new InvalidArgumentException('Historical RDS reservation manifest is not immutable.');
        }

        $expected = strtolower(trim((string) ($decoded['reserved_ids_sha256'] ?? '')));
        $ids = $decoded['reserved_ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            throw new InvalidArgumentException('Historical RDS reservation manifest has no reserved_ids.');
        }

        /** @var list<string> $normalizedIds */
        $normalizedIds = [];
        foreach ($ids as $id) {
            if (! is_string($id) || trim($id) === '') {
                throw new InvalidArgumentException('Historical RDS reservation manifest contains an invalid id.');
            }
            $normalizedIds[] = strtoupper(trim($id));
        }

        $canonical = implode("\n", $normalizedIds)."\n";
        $actual = hash('sha256', $canonical);
        if ($expected === '' || ! hash_equals($expected, $actual)) {
            throw new InvalidArgumentException('Historical RDS reservation manifest digest mismatch.');
        }

        $index = [];
        foreach ($normalizedIds as $normalized) {
            $index[$normalized] = true;
        }

        self::$reservedIndex = $index;
        self::$manifestSha256 = $expected;
    }
}
