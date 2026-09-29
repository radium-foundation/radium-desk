<?php

namespace Tests\Feature\CentralWallet\Support;

final class CentralWalletCeremonyMariaDbGate
{
    public const DATABASE = 'radium_desk_cw_ceremony_test';

    /**
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        return ['127.0.0.1', 'localhost', '::1'];
    }

    public static function isAllowedHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        if (str_contains($host, '/') || str_contains($host, '\\') || str_contains($host, '@')) {
            return false;
        }

        return in_array($host, self::allowedHosts(), true);
    }

    public static function isAllowedDatabase(string $database): bool
    {
        return $database === self::DATABASE;
    }

    public static function isAllowedAppEnv(string $env): bool
    {
        return in_array(strtolower(trim($env)), ['local', 'testing', 'development'], true);
    }
}
