<?php

namespace App\Enums;

enum ServiceCaseSlaStatus: string
{
    case WithinSla = 'within_sla';
    case Warning = 'warning';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::WithinSla => 'Within SLA',
            self::Warning => 'Warning',
            self::Overdue => 'Overdue',
        };
    }

    public function indicator(): string
    {
        return match ($this) {
            self::WithinSla => '🟢',
            self::Warning => '🟡',
            self::Overdue => '🔴',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::WithinSla => 'sla-status--within',
            self::Warning => 'sla-status--warning',
            self::Overdue => 'sla-status--overdue',
        };
    }
}
