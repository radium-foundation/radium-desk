<?php

namespace App\CentralWallet\Application;

use App\Services\Refunds\WalletRefundDestinationResolver;
use InvalidArgumentException;

/**
 * Explicit allowlist for Lane-1 pilot refund migration manifests (no arbitrary sites).
 */
final class PilotRefundMigrationSitePolicy
{
    public const RDSERVICE_IN = 'rdservice.in';

    public const RADIUMBOX_COM = 'radiumbox.com';

    /**
     * @return list<string>
     */
    public static function allowedSites(): array
    {
        return [
            self::RDSERVICE_IN,
            self::RADIUMBOX_COM,
        ];
    }

    public static function assertPreparedRowSite(string $site): void
    {
        if (! in_array($site, self::allowedSites(), true)) {
            throw new InvalidArgumentException('pilot_refund_migration_site_not_allowed');
        }
    }

    public static function assertPreparedRowSiteMatchesOrder(string $site, string $orderNumber): void
    {
        self::assertPreparedRowSite($site);

        $resolver = app(WalletRefundDestinationResolver::class);
        $matches = match ($site) {
            self::RDSERVICE_IN => $resolver->isRdServiceIn($orderNumber),
            self::RADIUMBOX_COM => $resolver->isRadiumBox($orderNumber),
            default => false,
        };

        if (! $matches) {
            throw new InvalidArgumentException('pilot_refund_migration_site_order_mismatch');
        }
    }
}
