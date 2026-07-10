<?php

namespace App\Services\Cashfree;

use RuntimeException;

class CashfreeConfigurationValidator
{
    public const ERROR_MISSING_CLIENT_SECRET = 'Cashfree webhook signature verification is enabled (CASHFREE_VERIFY_SIGNATURE=true) but CASHFREE_CLIENT_SECRET is not configured.';

    public function isValid(): bool
    {
        return $this->failures() === [];
    }

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        if (! config('cashfree.verify_signature')) {
            return [];
        }

        if ($this->clientSecret() === '') {
            return [self::ERROR_MISSING_CLIENT_SECRET];
        }

        return [];
    }

    public function validate(): void
    {
        $failures = $this->failures();

        if ($failures !== []) {
            throw new RuntimeException($failures[0]);
        }
    }

    private function clientSecret(): string
    {
        return trim((string) config('cashfree.client_secret'));
    }
}
