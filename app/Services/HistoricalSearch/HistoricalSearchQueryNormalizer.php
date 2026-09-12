<?php

namespace App\Services\HistoricalSearch;

class HistoricalSearchQueryNormalizer
{
    public function __construct(
        private readonly HistoricalCanonicalEmailNormalizer $emailNormalizer,
    ) {}

    /**
     * @return array{
     *     token: string,
     *     email: ?string,
     *     phone: ?string,
     *     name_prefix: ?string,
     *     looks_like_email: bool,
     *     looks_like_phone: bool,
     *     looks_like_serial: bool
     * }
     */
    public function normalize(string $query): array
    {
        $token = trim($query);
        $compact = preg_replace('/\s+/', ' ', $token) ?? $token;

        $email = null;
        $phone = null;
        $namePrefix = null;
        $looksLikeEmail = str_contains($compact, '@');
        $looksLikePhone = false;
        $looksLikeSerial = false;

        if ($looksLikeEmail) {
            $email = $this->emailNormalizer->normalizeForSearch($compact);
        }

        $digits = preg_replace('/\D+/', '', $compact) ?? '';
        if ($digits !== '' && strlen($digits) >= 10 && strlen($digits) <= 15) {
            $looksLikePhone = true;
            $phone = $digits;
        }

        if (
            ! $looksLikeEmail
            && strlen($compact) >= 4
            && preg_match('/^[A-Za-z]/', $compact) === 1
            && ! preg_match('/^(RD|RS|RQ|RA|IN)/i', $compact)
        ) {
            $namePrefix = mb_strtolower(mb_substr($compact, 0, min(32, mb_strlen($compact))));
        }

        if (
            ! $looksLikeEmail
            && strlen($compact) >= 4
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_.]{3,}$/', $compact) === 1
        ) {
            $looksLikeSerial = true;
        }

        return [
            'token' => $compact,
            'email' => $email,
            'phone' => $phone,
            'name_prefix' => $namePrefix,
            'looks_like_email' => $looksLikeEmail,
            'looks_like_phone' => $looksLikePhone,
            'looks_like_serial' => $looksLikeSerial,
        ];
    }
}
