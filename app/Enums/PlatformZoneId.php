<?php

namespace App\Enums;

enum PlatformZoneId: string
{
    case CriticalAlerts = 'critical_alerts';
    case ExecutiveSnapshot = 'executive_snapshot';
    case PlatformHealth = 'platform_health';
    case IntegrationHealth = 'integration_health';
    case Performance = 'performance';
    case Automation = 'automation';
    case OperationsOverview = 'operations_overview';
    case FinanceOverview = 'finance_overview';
    case Communications = 'communications';
    case Tools = 'tools';

    public function label(): string
    {
        return match ($this) {
            self::CriticalAlerts => 'Critical Alerts',
            self::ExecutiveSnapshot => 'Executive Snapshot',
            self::PlatformHealth => 'Platform Health',
            self::IntegrationHealth => 'Integration Health',
            self::Performance => 'Performance',
            self::Automation => 'Automation',
            self::OperationsOverview => 'Operations Overview',
            self::FinanceOverview => 'Finance Overview',
            self::Communications => 'Communications',
            self::Tools => 'Tools & Diagnostics',
        };
    }

    /**
     * Refresh priority band (1 = highest).
     */
    public function refreshPriority(): int
    {
        return match ($this) {
            self::CriticalAlerts, self::ExecutiveSnapshot, self::PlatformHealth => 1,
            self::IntegrationHealth => 2,
            self::Performance, self::Automation => 3,
            self::Communications, self::FinanceOverview, self::OperationsOverview => 4,
            self::Tools => 5,
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::CriticalAlerts => 10,
            self::ExecutiveSnapshot => 20,
            self::PlatformHealth => 30,
            self::IntegrationHealth => 40,
            self::Performance => 50,
            self::Automation => 60,
            self::Communications => 70,
            self::FinanceOverview => 80,
            self::OperationsOverview => 90,
            self::Tools => 100,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CriticalAlerts => 'bi-exclamation-octagon',
            self::ExecutiveSnapshot => 'bi-speedometer2',
            self::PlatformHealth => 'bi-heart-pulse',
            self::IntegrationHealth => 'bi-plug',
            self::Performance => 'bi-graph-up',
            self::Automation => 'bi-robot',
            self::OperationsOverview => 'bi-sliders',
            self::FinanceOverview => 'bi-cash-stack',
            self::Communications => 'bi-chat-dots',
            self::Tools => 'bi-tools',
        };
    }

    public function domId(): string
    {
        return match ($this) {
            self::PlatformHealth => 'platform-health',
            default => 'platform-zone-'.$this->value,
        };
    }
}
