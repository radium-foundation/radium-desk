<?php

namespace App\Enums;

enum InterBranchReconciliationMode: string
{
    case LegacyPosInterBranch = 'legacy_pos_inter_branch';

    public function label(): string
    {
        return match ($this) {
            self::LegacyPosInterBranch => 'Legacy POS inter-branch',
        };
    }
}
