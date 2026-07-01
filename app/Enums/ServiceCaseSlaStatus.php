<?php

namespace App\Enums;

enum ServiceCaseSlaStatus: string
{
    case WithinSla = 'within_sla';
    case Warning = 'warning';
    case Overdue = 'overdue';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::WithinSla => 'Within SLA',
            self::Warning => 'Warning',
            self::Overdue => 'Overdue',
            self::Paused => 'Paused',
        };
    }

    public function indicator(): string
    {
        return match ($this) {
            self::WithinSla => '🟢',
            self::Warning => '🟡',
            self::Overdue => '🔴',
            self::Paused => '⏸',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::WithinSla => 'sla-status--within',
            self::Warning => 'sla-status--warning',
            self::Overdue => 'sla-status--overdue',
            self::Paused => 'sla-status--paused',
        };
    }

    public function tooltipDurationClass(): string
    {
        return match ($this) {
            self::WithinSla => 'dashboard-sla-tooltip-duration--within',
            self::Warning => 'dashboard-sla-tooltip-duration--warning',
            self::Overdue => 'dashboard-sla-tooltip-duration--overdue',
            self::Paused => 'dashboard-sla-tooltip-duration--paused',
        };
    }
}
