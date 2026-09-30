<?php

namespace App\CentralWallet\Domain;

final class BalanceMigrationIdempotencyKey
{
    public static function forSourceLedgerRow(string $sourceSiteCode, int $sourceUsersWalletId): string
    {
        $site = strtolower(trim($sourceSiteCode));

        return $site.'-migration:users_wallet:'.$sourceUsersWalletId;
    }

    public static function forSourceRetirement(string $sourceSiteCode, int $sourceUsersWalletId): string
    {
        $site = strtolower(trim($sourceSiteCode));

        return $site.'-migration-retire:users_wallet:'.$sourceUsersWalletId;
    }
}
