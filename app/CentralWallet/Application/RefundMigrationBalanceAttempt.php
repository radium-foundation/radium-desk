<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;

final class RefundMigrationBalanceAttempt
{
    public const METADATA_KEY = 'balance_migration_retry_attempt';

    /**
     * @return array{
     *     parent_operation_id: string,
     *     attempt_operation_id: string,
     *     cutover_idempotency_key: string,
     *     source_wallet_attempt: int,
     *     owner_recovery_ref: string,
     *     reprepared_at: string,
     *     reason: string
     * }|null
     */
    public static function fromMigration(CentralWalletRefundMigration $migration): ?array
    {
        $raw = $migration->metadata[self::METADATA_KEY] ?? null;
        if (! is_array($raw)) {
            return null;
        }

        $parent = trim((string) ($raw['parent_operation_id'] ?? ''));
        $attemptId = trim((string) ($raw['attempt_operation_id'] ?? ''));
        $idempotencyKey = trim((string) ($raw['cutover_idempotency_key'] ?? ''));

        if ($parent === '' || $attemptId === '' || $idempotencyKey === '') {
            return null;
        }

        return [
            'parent_operation_id' => $parent,
            'attempt_operation_id' => $attemptId,
            'cutover_idempotency_key' => $idempotencyKey,
            'source_wallet_attempt' => (int) ($raw['source_wallet_attempt'] ?? 0),
            'owner_recovery_ref' => (string) ($raw['owner_recovery_ref'] ?? ''),
            'reprepared_at' => (string) ($raw['reprepared_at'] ?? ''),
            'reason' => (string) ($raw['reason'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cutoverOverrides(CentralWalletRefundMigration $migration): array
    {
        $attempt = self::fromMigration($migration);
        if ($attempt === null) {
            return [
                'idempotency_key' => RefundMigrationIdempotencyKey::forRefund((int) $migration->refund_id),
            ];
        }

        return [
            'idempotency_key' => $attempt['cutover_idempotency_key'],
            'migration_operation_id' => $attempt['attempt_operation_id'],
            'source_wallet_attempt' => $attempt['source_wallet_attempt'],
        ];
    }
}
