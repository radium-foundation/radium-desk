<?php

namespace Tests\Feature\Refunds\Support;

use PDO;
use PHPUnit\Framework\AssertionFailedError;

final class WalletAutoApprovalConcurrencyMysqlAssertions
{
    public static function assertSingleApprovalWinner(PDO $pdo, int $refundId, int $orderId): void
    {
        $status = (string) $pdo->query('select status from refund_requests where id = '.$refundId)->fetchColumn();
        if ($status !== 'pending_execution') {
            throw new AssertionFailedError(
                'Expected refund '.$refundId.' to be pending_execution, got ['.$status.']',
            );
        }

        $pendingExecution = (int) $pdo->query(
            "select count(*) from refund_requests where order_id = {$orderId} and status = 'pending_execution'",
        )->fetchColumn();
        if ($pendingExecution !== 1) {
            throw new AssertionFailedError(
                'Expected exactly one pending_execution refund for order '.$orderId.', found '.$pendingExecution,
            );
        }

        $approvalAudits = (int) $pdo->query(
            "select count(*) from audit_logs where event = 'refund.approved' and auditable_id = {$refundId}",
        )->fetchColumn();
        if ($approvalAudits !== 1) {
            throw new AssertionFailedError(
                'Expected exactly one refund.approved audit for refund '.$refundId.', found '.$approvalAudits,
            );
        }

        $executionStartedAudits = (int) $pdo->query(
            "select count(*) from audit_logs where event = 'refund.execution_started' and auditable_id = {$refundId}",
        )->fetchColumn();
        if ($executionStartedAudits !== 1) {
            throw new AssertionFailedError(
                'Expected exactly one refund.execution_started audit for refund '.$refundId.', found '.$executionStartedAudits,
            );
        }

        $ledgerCredits = (int) $pdo->query(
            "select count(*) from central_wallet_ledger_entries where entry_type = 'credit'",
        )->fetchColumn();
        if ($ledgerCredits !== 0) {
            throw new AssertionFailedError(
                'Approval must not create wallet ledger credits (execution-separated); found '.$ledgerCredits,
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    public static function assertExactlyOneApprovalSuccess(array $results): array
    {
        $successes = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $failures = array_values(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) !== true));

        if (count($successes) !== 1) {
            throw new AssertionFailedError(
                'Expected exactly one worker success, found '.count($successes).': '.json_encode($results),
            );
        }

        if (count($failures) !== 1) {
            throw new AssertionFailedError(
                'Expected exactly one worker failure, found '.count($failures).': '.json_encode($results),
            );
        }

        foreach ($failures as $failure) {
            if (($failure['validation'] ?? false) !== true) {
                throw new AssertionFailedError(
                    'Losing worker must fail with validation guard: '.json_encode($failure),
                );
            }
        }

        return $successes[0];
    }
}
