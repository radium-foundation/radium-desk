<?php

namespace Tests\Feature\PreShipmentCancellation\Support;

final class PreShipmentCancellationMariaDbGate
{
    public const DATABASE = 'radium_desk_preship_cancel_mariadb_test';

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

        return in_array($host, self::allowedHosts(), true);
    }

    public static function isAllowedDatabase(string $database): bool
    {
        return $database === self::DATABASE;
    }

    public static function isAllowedAppEnv(string $env): bool
    {
        return in_array(strtolower(trim($env)), ['local', 'testing', 'development', 'ci'], true);
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function forceProcessEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
