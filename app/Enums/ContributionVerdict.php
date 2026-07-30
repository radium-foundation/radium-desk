<?php

namespace App\Enums;

/**
 * Workforce Rule Book §5 — Contribution verdicts only.
 */
enum ContributionVerdict: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::High => 'High',
            self::Normal => 'Normal',
            self::Low => 'Low',
            self::None => 'None',
        };
    }

    /**
     * Rule Book: Qualified Contribution = Normal or High.
     */
    public function isQualified(): bool
    {
        return $this === self::High || $this === self::Normal;
    }
}
