<?php

namespace App\Services\StatutoryInvoice;

use App\Models\ServiceItem;

/**
 * Canonical RD service statutory display name (SAC 998313).
 *
 * Channel payloads may still carry legacy descriptions with embedded SAC text.
 * Normalization applies at invoice presentation and service-catalog display only.
 */
final class RdServiceStatutoryDisplayName
{
    public const CANONICAL = 'IT Consulting & Support Service';

    public static function canonical(): string
    {
        $configured = config('statutory_invoices.service_sac.rd_service.display_name');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : self::CANONICAL;
    }

    public static function catalogName(?ServiceItem $item): ?string
    {
        if ($item === null) {
            return null;
        }

        $item->loadMissing('category');
        if ($item->category?->code === 'rd_service' && trim((string) $item->sac_code) === '998313') {
            return self::canonical();
        }

        return $item->name;
    }

    public static function matchesDescription(?string $description): bool
    {
        $haystack = strtolower(trim((string) $description));
        if ($haystack === '') {
            return false;
        }

        if (str_contains($haystack, strtolower(self::canonical()))) {
            return true;
        }

        $needles = config('statutory_invoices.service_sac.rd_service.description_needles', []);
        if (! is_array($needles)) {
            return false;
        }

        foreach ($needles as $needle) {
            if (! is_string($needle) || trim($needle) === '') {
                continue;
            }
            if (str_contains($haystack, strtolower(trim($needle)))) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeInvoiceDescription(string $description): string
    {
        if (! self::matchesDescription($description)) {
            return $description;
        }

        $normalized = preg_replace('/\s*\(SAC\s*-\s*99831[34]\)\s*/i', ' ', $description) ?? $description;

        foreach (self::legacyBaseNames() as $legacy) {
            $normalized = str_ireplace($legacy, self::canonical(), $normalized);
        }

        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*-\s*-\s*/', ' - ', $normalized) ?? $normalized;

        return trim($normalized, " \t\n\r\0\x0B-");
    }

    /**
     * @return list<string>
     */
    private static function legacyBaseNames(): array
    {
        return [
            'information technology (it) consulting & support services',
            'information technology (it) consulting and support services',
        ];
    }
}
