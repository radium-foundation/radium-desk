<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use InvalidArgumentException;

final class BalanceMigrationStateMachine
{
    /**
     * @return list<BalanceMigrationStatus>
     */
    public function allowedTransitions(BalanceMigrationStatus $from): array
    {
        return match ($from) {
            BalanceMigrationStatus::Initiated => [
                BalanceMigrationStatus::Prepared,
                BalanceMigrationStatus::Aborted,
            ],
            BalanceMigrationStatus::Prepared => [
                BalanceMigrationStatus::CentralCredited,
                BalanceMigrationStatus::Aborted,
            ],
            BalanceMigrationStatus::CentralCredited => [
                BalanceMigrationStatus::SourceRetired,
                BalanceMigrationStatus::Compensating,
            ],
            BalanceMigrationStatus::SourceRetired => [
                BalanceMigrationStatus::Reconciled,
                BalanceMigrationStatus::Compensating,
            ],
            BalanceMigrationStatus::Compensating => [
                BalanceMigrationStatus::Aborted,
            ],
            BalanceMigrationStatus::Reconciled,
            BalanceMigrationStatus::Aborted => [],
        };
    }

    public function assertCanTransition(BalanceMigrationStatus $from, BalanceMigrationStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        if (! in_array($to, $this->allowedTransitions($from), true)) {
            throw new InvalidArgumentException(
                'Invalid balance migration transition from '.$from->value.' to '.$to->value
            );
        }
    }

    public function isTerminal(BalanceMigrationStatus $status): bool
    {
        return in_array($status, [
            BalanceMigrationStatus::Reconciled,
            BalanceMigrationStatus::Aborted,
        ], true);
    }
}
