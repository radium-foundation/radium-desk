<?php

namespace App\Services\HistoricalSearch;

/**
 * Chooses minimal historical search strategies per query shape.
 */
class HistoricalSearchQueryClassifier
{
    public function __construct(
        private readonly HistoricalCanonicalEmailNormalizer $emailNormalizer,
    ) {}

    /**
     * @return list<string> strategy keys: token, email, phone, name, serial, awb, product
     */
    public function strategies(string $query): array
    {
        $token = trim($query);
        if ($token === '') {
            return [];
        }

        if (str_contains($token, '@')) {
            return ['email', 'token'];
        }

        if ($this->looksLikeAwb($token)) {
            return ['token', 'awb'];
        }

        $digits = preg_replace('/\D+/', '', $token) ?? '';
        $isPhone = $digits !== '' && strlen($digits) >= 10 && strlen($digits) <= 15
            && strlen($digits) >= (int) (strlen($token) * 0.7);

        if ($isPhone) {
            return ['phone', 'token'];
        }

        if ($this->looksLikeProductRef($token)) {
            return ['token', 'product'];
        }

        if ($this->looksLikeSerial($token)) {
            return ['token', 'serial'];
        }

        if ($this->looksLikeOrderOrInvoiceCode($token)) {
            return ['token'];
        }

        if ($this->looksLikeName($token)) {
            return ['name', 'token'];
        }

        return ['token'];
    }

    private function looksLikeOrderOrInvoiceCode(string $token): bool
    {
        return preg_match('/^(RD|RS|RQ|RA|INV|IN)[A-Z0-9\-_.]+$/i', $token) === 1
            || preg_match('/^[A-Z]{2,8}[0-9]{3,}$/i', $token) === 1
            || preg_match('/^[A-Z]{2,12}$/', $token) === 1
            || (preg_match('/^[A-Z0-9]{2,12}$/i', $token) === 1 && preg_match('/\d/', $token) === 1);
    }

    private function looksLikeAwb(string $token): bool
    {
        // AWB barcodes are typically 12–20 digits; 10-digit strings are treated as phone.
        return preg_match('/^\d{12,20}$/', $token) === 1;
    }

    private function looksLikeProductRef(string $token): bool
    {
        return preg_match('/^\d{1,6}$/', $token) === 1
            || preg_match('/^[A-Z]{1,4}[-_]?\d{2,}$/i', $token) === 1;
    }

    private function looksLikeSerial(string $token): bool
    {
        return ! str_contains($token, '@')
            && strlen($token) >= 6
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_.]{5,}$/', $token) === 1
            && ! $this->looksLikeOrderOrInvoiceCode($token);
    }

    private function looksLikeName(string $token): bool
    {
        return strlen($token) >= 3
            && preg_match('/^[A-Za-z]/', $token) === 1
            && ! preg_match('/^(RD|RS|RQ|RA|IN)/i', $token);
    }
}
