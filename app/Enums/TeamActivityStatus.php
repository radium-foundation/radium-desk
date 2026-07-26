<?php

namespace App\Enums;

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
    case NotStartedShift = 'not_started_shift';
    case Ira = 'ira';
    case Unknown = 'unknown';

    public function label(): string
    {
        $configured = config('dashboard-team-activity.statuses.'.$this->value.'.label');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return match ($this) {
            self::Working, self::Idle, self::Login => 'Active',
            self::WaitingCustomer => 'Waiting Customer',
            self::OnIvr => 'On IVR',
            self::Email => 'Email',
            self::Whatsapp => 'WhatsApp',
            self::Remark => 'Remark',
            self::Assignment => 'Assignment',
            self::StatusChanged => 'Status Changed',
            self::SerialUpdated => 'Serial Updated',
            self::ModelUpdated => 'Model Updated',
            self::Refund => 'Refund',
            self::Approval => 'Approval',
            self::AutoLogout => 'Auto Logged Out',
            self::Logout, self::OffDuty => 'Off Duty',
            self::Break => 'Break',
            self::Leave => 'Leave',
            self::NotStartedShift => 'Not Started Shift',
            self::Ira => 'IRA',
            self::Unknown => 'Unknown',
        };
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
