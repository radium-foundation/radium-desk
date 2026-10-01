<?php

namespace App\CentralWallet\Domain;

final class E1IdentityMigrationIdempotencyKey
{
    public static function forRefund(int $refundId): string
    {
        return 'cw-e1-refund-migration-'.$refundId;
    }

    public static function ledgerSourceReference(int $refundId): string
    {
        return 'e1-identity-migration-refund-'.$refundId;
    }
}
