<?php

namespace App\Services\HistoricalSearch;

/**
 * Canonical email normalization for historical search lookups.
 *
 * Some ETL rows retain a leading grave accent (U+0060) from source formatting.
 * This normalizer affects search matching only — not stored provenance.
 */
class HistoricalCanonicalEmailNormalizer
{
    /**
     * Normalize a user query or canonical value for email search comparison.
     */
    public function normalizeForSearch(string $value): string
    {
        $email = strtolower(trim($value));

        return $this->stripDefectWrappers($email);
    }

    /**
     * Values to match against hist_search_document.email_norm (exact + known defect forms).
     *
     * @return list<string>
     */
    public function searchVariants(string $value): array
    {
        $canonical = $this->normalizeForSearch($value);

        if ($canonical === '' || ! str_contains($canonical, '@')) {
            return [];
        }

        $variants = [$canonical];

        $defect = '`'.$canonical;
        if (! in_array($defect, $variants, true)) {
            $variants[] = $defect;
        }

        return array_values(array_unique($variants));
    }

    private function stripDefectWrappers(string $email): string
    {
        return ltrim(rtrim($email, " \t\n\r\0\x0B`"), " \t\n\r\0\x0B`");
    }
}
