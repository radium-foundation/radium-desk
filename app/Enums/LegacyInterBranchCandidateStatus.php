<?php

namespace App\Enums;

enum LegacyInterBranchCandidateStatus: string
{
    case Reconcilable = 'reconcilable';
    case Blocked = 'blocked';
    case AlreadyReconciled = 'already_reconciled';
    case RequiresManualReview = 'requires_manual_review';

    public function label(): string
    {
        return match ($this) {
            self::Reconcilable => 'Reconcilable',
            self::Blocked => 'Blocked',
            self::AlreadyReconciled => 'Already reconciled',
            self::RequiresManualReview => 'Requires manual review',
        };
    }
}
