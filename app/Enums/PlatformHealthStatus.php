<?php

namespace App\Enums;

enum PlatformHealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
            self::Disabled => 'Disabled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Warning => 'warning',
            self::Critical => 'danger',
            self::Disabled => 'secondary',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Critical => 40,
            self::Warning => 30,
            self::Disabled => 20,
            self::Healthy => 10,
        };
    }

    public static function worst(self ...$statuses): self
    {
        $worst = self::Healthy;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public static function fromOperations(OperationsHealthStatus $status): self
    {
        return match ($status) {
            OperationsHealthStatus::Healthy => self::Healthy,
            OperationsHealthStatus::Warning => self::Warning,
            OperationsHealthStatus::Failed => self::Critical,
            OperationsHealthStatus::Disabled, OperationsHealthStatus::NotConfigured => self::Disabled,
        };
    }
}
