<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use InvalidArgumentException;

final class RefundMigrationStateMachine
{
    /**
     * @return list<RefundMigrationStatus>
     */
    public function allowedTransitions(RefundMigrationStatus $from): array
    {
        return match ($from) {
            RefundMigrationStatus::Pending => [
                RefundMigrationStatus::Prepared,
                RefundMigrationStatus::Failed,
            ],
            RefundMigrationStatus::Prepared => [
                RefundMigrationStatus::SourceDebitPending,
                RefundMigrationStatus::CwCreditPending,
                RefundMigrationStatus::Failed,
            ],
            RefundMigrationStatus::SourceDebitPending => [
                RefundMigrationStatus::SourceDebited,
                RefundMigrationStatus::Failed,
                RefundMigrationStatus::Compensating,
            ],
            RefundMigrationStatus::SourceDebited => [
                RefundMigrationStatus::CwCreditPending,
                RefundMigrationStatus::CwCredited,
                RefundMigrationStatus::Compensating,
            ],
            RefundMigrationStatus::CwCreditPending => [
                RefundMigrationStatus::CwCredited,
                RefundMigrationStatus::Failed,
                RefundMigrationStatus::Compensating,
            ],
            RefundMigrationStatus::CwCredited => [
                RefundMigrationStatus::Reconciled,
                RefundMigrationStatus::ReconciliationRequired,
                RefundMigrationStatus::Compensating,
            ],
            RefundMigrationStatus::Reconciled => [
                RefundMigrationStatus::ReconciliationRequired,
                RefundMigrationStatus::Compensating,
                RefundMigrationStatus::Reversed,
            ],
            RefundMigrationStatus::Failed => [
                RefundMigrationStatus::Prepared,
            ],
            RefundMigrationStatus::Compensating => [
                RefundMigrationStatus::Compensated,
                RefundMigrationStatus::Failed,
            ],
            RefundMigrationStatus::Compensated => [
                RefundMigrationStatus::Reversed,
                RefundMigrationStatus::ReconciliationRequired,
            ],
            RefundMigrationStatus::ReconciliationRequired,
            RefundMigrationStatus::Reversed => [],
        };
    }

    public function assertCanTransition(RefundMigrationStatus $from, RefundMigrationStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        if (! in_array($to, $this->allowedTransitions($from), true)) {
            throw new InvalidArgumentException(
                'Invalid refund migration transition from '.$from->value.' to '.$to->value
            );
        }
    }

    public function isTerminal(RefundMigrationStatus $status): bool
    {
        return in_array($status, [
            RefundMigrationStatus::Reconciled,
            RefundMigrationStatus::Compensated,
            RefundMigrationStatus::Reversed,
        ], true);
    }

    public function isExecutable(RefundMigrationStatus $status): bool
    {
        return in_array($status, [
            RefundMigrationStatus::Prepared,
            RefundMigrationStatus::SourceDebitPending,
            RefundMigrationStatus::SourceDebited,
            RefundMigrationStatus::CwCreditPending,
            RefundMigrationStatus::CwCredited,
        ], true);
    }
}
