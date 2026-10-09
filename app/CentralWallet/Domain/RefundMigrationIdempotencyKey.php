<?php

namespace App\CentralWallet\Domain;

final class RefundMigrationIdempotencyKey
{
    public static function forRefund(int $refundId): string
    {
        return 'desk-refund-migration:refund_requests:'.$refundId;
    }

    public static function forRefundBalanceAttempt(int $refundId, string $attemptOperationId): string
    {
        $attemptOperationId = strtolower(trim($attemptOperationId));

        return 'desk-refund-migration:refund_requests:'.$refundId.':attempt:'.$attemptOperationId;
    }

    public static function ledgerSourceReference(int $refundId): string
    {
        return 'refund_requests:'.$refundId;
    }

    public static function rollback(int $refundId): string
    {
        return 'desk-refund-migration-rollback:refund_requests:'.$refundId;
    }
}
