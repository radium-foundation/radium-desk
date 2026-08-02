<?php

namespace App\Enums;

enum PlatformAlertSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Information = 'information';
    case Healthy = 'healthy';
    case Disabled = 'disabled';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Warning => 'Warning',
            self::Information => 'Information',
            self::Healthy => 'Healthy',
            self::Disabled => 'Disabled',
            self::Unknown => 'Unknown',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Critical => 10,
            self::Warning => 20,
            self::Information => 30,
            self::Unknown => 40,
            self::Disabled => 50,
            self::Healthy => 60,
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::Warning => 'warning',
            self::Information => 'info',
            self::Healthy => 'success',
            self::Disabled => 'secondary',
            self::Unknown => 'secondary',
        };
    }

    public static function fromPlatformHealth(\App\Enums\PlatformHealthStatus $status): self
    {
        return match ($status) {
            \App\Enums\PlatformHealthStatus::Critical => self::Critical,
            \App\Enums\PlatformHealthStatus::Warning => self::Warning,
            \App\Enums\PlatformHealthStatus::Healthy => self::Healthy,
            \App\Enums\PlatformHealthStatus::Disabled => self::Disabled,
        };
    }

    public static function fromIntegration(\App\Enums\IntegrationHealthStatus $status): self
    {
        return match ($status) {
            \App\Enums\IntegrationHealthStatus::Critical,
            \App\Enums\IntegrationHealthStatus::Unavailable => self::Critical,
            \App\Enums\IntegrationHealthStatus::Warning,
            \App\Enums\IntegrationHealthStatus::Loading => self::Warning,
            \App\Enums\IntegrationHealthStatus::Healthy => self::Healthy,
            \App\Enums\IntegrationHealthStatus::Disabled,
            \App\Enums\IntegrationHealthStatus::NotConfigured => self::Disabled,
        };
    }
}
