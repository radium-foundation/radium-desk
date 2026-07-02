<?php

namespace App\Enums;

enum OperationsHealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Failed = 'failed';
    case Disabled = 'disabled';
    case NotConfigured = 'not_configured';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Warning => 'Warning',
            self::Failed => 'Failed',
            self::Disabled => 'Disabled',
            self::NotConfigured => 'Not Configured',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Warning => 'warning',
            self::Failed => 'danger',
            self::Disabled => 'secondary',
            self::NotConfigured => 'light text-dark border',
        };
    }
}
