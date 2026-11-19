<?php

namespace App\Enums;

enum LeaveApprovalSource: string
{
    case Manual = 'manual';
    case Auto = 'auto';
    case ManualException = 'manual_exception';
    case AuthorizedOverride = 'authorized_override';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual approval',
            self::Auto => 'Auto-approved',
            self::ManualException => 'Manual exception',
            self::AuthorizedOverride => 'Authorized override',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
