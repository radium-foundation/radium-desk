<?php

namespace App\Enums;

/**
 * Normalized integration health status for Platform Integration Health cards.
 */
enum IntegrationHealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Disabled = 'disabled';
    case NotConfigured = 'not_configured';
    case Loading = 'loading';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
            self::Disabled => 'Disabled',
            self::NotConfigured => 'Not Configured',
            self::Loading => 'Loading',
            self::Unavailable => 'Unavailable',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Warning => 'warning',
            self::Critical => 'danger',
            self::Disabled => 'secondary',
            self::NotConfigured => 'secondary',
            self::Loading => 'info',
            self::Unavailable => 'dark',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Critical, self::Unavailable => 50,
            self::Warning => 40,
            self::NotConfigured, self::Disabled => 30,
            self::Loading => 20,
            self::Healthy => 10,
        };
    }

    public function toPlatform(): PlatformHealthStatus
    {
        return match ($this) {
            self::Healthy => PlatformHealthStatus::Healthy,
            self::Warning, self::Loading => PlatformHealthStatus::Warning,
            self::Critical, self::Unavailable => PlatformHealthStatus::Critical,
            self::Disabled, self::NotConfigured => PlatformHealthStatus::Disabled,
        };
    }

    public static function fromOperations(OperationsHealthStatus $status): self
    {
        return match ($status) {
            OperationsHealthStatus::Healthy => self::Healthy,
            OperationsHealthStatus::Warning => self::Warning,
            OperationsHealthStatus::Failed => self::Critical,
            OperationsHealthStatus::Disabled => self::Disabled,
            OperationsHealthStatus::NotConfigured => self::NotConfigured,
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
}
