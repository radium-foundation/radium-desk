<?php

namespace App\Enums;

enum PlatformOverallHealthStatus: string
{
    case Healthy = 'healthy';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
            self::Unavailable => 'Unavailable',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Warning => 'warning',
            self::Critical => 'danger',
            self::Unavailable => 'secondary',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Critical => 40,
            self::Warning => 30,
            self::Unavailable => 20,
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

    public static function fromAlertSeverity(PlatformAlertSeverity $severity): self
    {
        return match ($severity) {
            PlatformAlertSeverity::Critical => self::Critical,
            PlatformAlertSeverity::Warning, PlatformAlertSeverity::Information => self::Warning,
            PlatformAlertSeverity::Healthy => self::Healthy,
            PlatformAlertSeverity::Disabled, PlatformAlertSeverity::Unknown => self::Unavailable,
        };
    }

    public static function fromPlatformHealth(\App\Enums\PlatformHealthStatus $status): self
    {
        return match ($status) {
            \App\Enums\PlatformHealthStatus::Healthy => self::Healthy,
            \App\Enums\PlatformHealthStatus::Warning => self::Warning,
            \App\Enums\PlatformHealthStatus::Critical => self::Critical,
            \App\Enums\PlatformHealthStatus::Disabled => self::Unavailable,
        };
    }
}
