<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Read-only cohort readiness for paired legacy-wallet → Desk Central Wallet reconciliation.
 */
final class HistoricalWalletRefundReconciliationCohortAnalyzer
{
    /**
     * Migration journal states that are not safe to treat as batch execution-ready.
     *
     * @var list<RefundMigrationStatus>
     */
    private const UNRESOLVED_MIGRATION_STATUSES = [
        RefundMigrationStatus::Pending,
        RefundMigrationStatus::Prepared,
        RefundMigrationStatus::SourceDebitPending,
        RefundMigrationStatus::SourceDebited,
        RefundMigrationStatus::CwCreditPending,
        RefundMigrationStatus::CwCredited,
        RefundMigrationStatus::Compensating,
        RefundMigrationStatus::Compensated,
        RefundMigrationStatus::ReconciliationRequired,
        RefundMigrationStatus::Reversed,
    ];

    public function __construct(
        private readonly WalletRefundAccountingDiscrepancyClassifier $classifier,
    ) {}

    /**
     * @return array{
     *     generated_at: string,
     *     summary: array{eligible: int, blocked: int, already_reconciled: int},
     *     eligible: list<array<string, mixed>>,
     *     blocked: list<array<string, mixed>>,
     * }
     */
    public function analyze(?int $limit = null, ?int $afterRefundId = null): array
    {
        $report = $this->classifier->buildReport($limit, $afterRefundId);

        $eligible = [];
        $blocked = [];
        $alreadyReconciled = 0;

        foreach ($report['rows'] as $row) {
            if (($row['policy_violation'] ?? false) !== true) {
                if (($row['classification'] ?? '') === 'migration_reconciled') {
                    $alreadyReconciled++;
                }

                continue;
            }

            $refundId = (int) ($row['refund_id'] ?? 0);
            $blockers = $this->blockersForViolationRow($row);

            if ($blockers === []) {
                $eligible[] = array_merge($row, ['reconciliation_lane' => 'lane_1_spoke_cutover']);
            } else {
                $blocked[] = array_merge($row, ['blockers' => $blockers]);
            }
        }

        return [
            'generated_at' => $report['generated_at'],
            'summary' => [
                'eligible' => count($eligible),
                'blocked' => count($blocked),
                'already_reconciled' => $alreadyReconciled,
            ],
            'eligible' => $eligible,
            'blocked' => $blocked,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{code: string, message: string}>
     */
    private function blockersForViolationRow(array $row): array
    {
        $blockers = [];
        $refundId = (int) ($row['refund_id'] ?? 0);
        $orderId = (int) ($row['order_id'] ?? 0);

        if ($refundId <= 0 || $orderId <= 0) {
            $blockers[] = [
                'code' => 'missing_refund_or_order',
                'message' => 'Refund or order identity is missing',
            ];

            return $blockers;
        }

        $migration = CentralWalletRefundMigration::query()->where('refund_id', $refundId)->first();
        if ($migration !== null) {
            if ($migration->status === RefundMigrationStatus::Reconciled) {
                $blockers[] = [
                    'code' => 'migration_already_reconciled',
                    'message' => 'Refund migration journal is already reconciled',
                ];
            } elseif (in_array($migration->status, self::UNRESOLVED_MIGRATION_STATUSES, true)) {
                $blockers[] = [
                    'code' => 'migration_journal_unresolved',
                    'message' => 'Refund migration journal exists in status '.$migration->status->value.' and is not execution-ready',
                ];
            }
        }

        if (($row['desk_ledger_credit_count'] ?? 0) > 0) {
            $blockers[] = [
                'code' => 'desk_ledger_credit_exists',
                'message' => 'A posted Desk ledger credit already exists for this refund reference',
            ];
        }

        $order = Order::query()->find($orderId);
        $customerId = trim((string) ($order?->customer_id ?? ''));
        if ($customerId === '' || ! Str::isUuid($customerId)) {
            $blockers[] = [
                'code' => 'order_customer_unbound',
                'message' => 'Desk order is not bound to a Central Wallet customer UUID',
            ];
        }

        $reference = trim((string) ($row['reference_no'] ?? ''));
        if ($reference === '') {
            $blockers[] = [
                'code' => 'missing_refund_reference',
                'message' => 'Refund reference is required for paired reconciliation',
            ];
        }

        $amount = trim((string) ($row['refund_amount'] ?? ''));
        if ($amount === '' || bccomp($amount, '0', 2) <= 0) {
            $blockers[] = [
                'code' => 'invalid_refund_amount',
                'message' => 'Refund amount must be positive',
            ];
        }

        if (trim((string) ($row['execution_transaction_id'] ?? '')) === '') {
            $blockers[] = [
                'code' => 'missing_spoke_execution_evidence',
                'message' => 'No spoke execution evidence on the Desk refund record',
            ];
        }

        return $blockers;
    }
}
