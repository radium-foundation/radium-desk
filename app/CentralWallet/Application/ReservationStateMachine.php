<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\ReservationState;
use InvalidArgumentException;

/**
 * Fail-closed reservation lifecycle for Central Wallet checkout holds.
 *
 * Legal transitions:
 * - ACTIVE → COMMITTED | RELEASED | EXPIRED
 * - COMMITTED | RELEASED | EXPIRED are terminal
 */
final class ReservationStateMachine
{
    /**
     * @return list<ReservationState>
     */
    public function allowedTransitions(ReservationState $from): array
    {
        return match ($from) {
            ReservationState::Active => [
                ReservationState::Committed,
                ReservationState::Released,
                ReservationState::Expired,
            ],
            ReservationState::Committed,
            ReservationState::Released,
            ReservationState::Expired => [],
        };
    }

    public function assertCanTransition(ReservationState $from, ReservationState $to): void
    {
        if ($from === $to) {
            return;
        }

        if (! in_array($to, $this->allowedTransitions($from), true)) {
            throw new InvalidArgumentException(
                'Invalid reservation transition from '.$from->value.' to '.$to->value
            );
        }
    }

    public function isTerminal(ReservationState $state): bool
    {
        return in_array($state, [
            ReservationState::Committed,
            ReservationState::Released,
            ReservationState::Expired,
        ], true);
    }
}
