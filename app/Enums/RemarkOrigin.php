<?php

namespace App\Enums;

enum RemarkOrigin: string
{
    case Manual = 'manual';
    case System = 'system';

    public function countsForTeamActivityKpi(): bool
    {
        return $this === self::Manual;
    }

    public static function countsForTeamActivityKpiValue(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return self::tryFrom($value)?->countsForTeamActivityKpi() ?? true;
    }
}
