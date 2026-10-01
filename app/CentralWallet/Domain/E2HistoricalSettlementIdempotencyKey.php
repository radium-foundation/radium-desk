<?php

namespace App\CentralWallet\Domain;

final class E2HistoricalSettlementIdempotencyKey
{
    public static function forRefund(int $refundId): string
    {
        return 'desk-refund-historical-settlement:'.$refundId;
    }

    public static function ledgerSourceReference(int $refundId): string
    {
        return self::forRefund($refundId);
    }

    public static function rollback(int $refundId): string
    {
        return 'desk-refund-historical-settlement-rollback:'.$refundId;
    }
}
