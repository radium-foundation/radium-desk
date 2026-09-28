<?php

namespace App\CentralWallet\Application;

final class IdempotencyResponseSanitizer
{
    private const REDACTED_KEYS = [
        'authorization',
        'token',
        'password',
        'secret',
        'otp',
        'bearer',
        'api_key',
        'card_number',
        'cvv',
    ];

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function sanitize(array $body): array
    {
        $sanitized = [];

        foreach ($body as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitize($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
