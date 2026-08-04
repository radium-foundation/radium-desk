<?php

namespace App\Enums;

use App\Support\Dashboard\TeamActivityWorkforceStatus;

enum TeamActivityStatus: string
{
    case Working = 'working';
    case Idle = 'idle';
    case WaitingCustomer = 'waiting_customer';
    case OnIvr = 'on_ivr';
    case Email = 'email';
    case Whatsapp = 'whatsapp';
    case Remark = 'remark';
    case Assignment = 'assignment';
    case StatusChanged = 'status_changed';
    case SerialUpdated = 'serial_updated';
    case ModelUpdated = 'model_updated';
    case Refund = 'refund';
    case Approval = 'approval';
    case AutoLogout = 'auto_logout';
    case Logout = 'logout';
    case Login = 'login';
    case Break = 'break';
    case Leave = 'leave';
    case OffDuty = 'off_duty';
    case Offline = 'offline';
    case NotLoggedIn = 'not_logged_in';
    case NoSchedule = 'no_schedule';
    case NotStartedShift = 'not_started_shift';
    case Ira = 'ira';
    case Unknown = 'unknown';

    public function label(): string
    {
        return TeamActivityWorkforceStatus::labelFor($this);
    }

    public function tone(): string
    {
        // Enterprise panel: neutral indicator only (no coloured status dots).
        return 'muted';
    }

    public static function tryFromConfig(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
