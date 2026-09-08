<?php

namespace App\Enums;

enum HardwareAwaitingFulfilmentReason: string
{
    case ReviewCandidate = 'review_candidate';
    case Frozen = 'frozen';
    case Hold = 'hold';
    case Blocked = 'blocked';
    case Unpaid = 'unpaid';
    case PreCutoff = 'pre_cutoff';
    case DeskAlreadyCompleted = 'desk_already_completed';
    case Rin = 'rin';

    public function label(): string
    {
        return match ($this) {
            self::ReviewCandidate => 'Review candidate',
            self::Frozen => 'Frozen',
            self::Hold => 'HOLD',
            self::Blocked => 'Blocked',
            self::Unpaid => 'Unpaid',
            self::PreCutoff => 'Historical / pre-cutoff',
            self::DeskAlreadyCompleted => 'Already completed on Desk',
            self::Rin => 'RIN — not Desk fulfilment',
        };
    }

    public function operatorNote(): string
    {
        return match ($this) {
            self::ReviewCandidate => 'Paid RDE after the isolated cutoff, with no fulfilment and no Desk serial/transaction. Review the order. Do not create a fulfilment from this page.',
            self::Frozen => 'Owner-frozen. Do not ingest or ship.',
            self::Hold => 'Owner-HOLD. Do not ingest or ship.',
            self::Blocked => 'Blocked until authorized. Do not ingest or ship.',
            self::Unpaid => 'No Cashfree payment on the Desk order. Do not ingest.',
            self::PreCutoff => 'Desk created_at is before 2026-09-05 00:00 IST. Isolated ingest refuses this cutoff. Do not mass-ingest history.',
            self::DeskAlreadyCompleted => 'Desk already has a serial or transaction. Treat as processed outside Hardware Fulfilment unless an owner authorizes a single-order review.',
            self::Rin => 'RIN support orders are not Desk hardware fulfilment.',
        };
    }
}
