<?php

namespace App\Services\Shipping;

/**
 * Request-scoped Shiprocket audit context for correlating gateway HTTP with a Desk shipment.
 */
final class ShiprocketApiAuditScope
{
    private static ?ShiprocketApiAuditContext $context = null;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(?ShiprocketApiAuditContext $context, callable $callback): mixed
    {
        $previous = self::$context;
        self::$context = $context;

        try {
            return $callback();
        } finally {
            self::$context = $previous;
        }
    }

    public static function current(): ?ShiprocketApiAuditContext
    {
        return self::$context;
    }

    /** @internal test helper */
    public static function resetForTesting(): void
    {
        self::$context = null;
    }
}
