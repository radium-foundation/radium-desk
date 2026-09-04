<?php

namespace Tests\Feature\Inventory\Support;

final class InventoryPosMysqlGate
{
    public const DATABASE = 'radium_desk_inventory_pos_test';

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
