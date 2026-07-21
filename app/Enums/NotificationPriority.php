<?php

namespace App\Enums;

enum NotificationPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';
    case Silent = 'silent';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Normal => 'Normal',
            self::Silent => 'Silent',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Normal => 2,
            self::Silent => 1,
        };
    }

    public function meetsThreshold(self $threshold): bool
    {
        return $this->rank() >= $threshold->rank();
    }

    public static function fromAlertSeverity(AlertSeverity $severity): self
    {
        return match ($severity) {
            AlertSeverity::Critical => self::Critical,
            AlertSeverity::High => self::High,
            AlertSeverity::Medium => self::Normal,
            AlertSeverity::Info => self::Silent,
        };
    }
}
