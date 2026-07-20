<?php

namespace App\Enums;

enum PlatformDashboardSection: string
{
    case Executive = 'executive';
    case PlatformHealth = 'platform_health';
    case Operations = 'operations';
    case Workforce = 'workforce';
    case Customers = 'customers';
    case Automation = 'automation';
    case Finance = 'finance';
    case Communications = 'communications';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Executive => 'Executive Snapshot',
            self::PlatformHealth => 'Platform Health',
            self::Operations => 'Business Operations',
            self::Workforce => 'Workforce',
            self::Customers => 'Customer Operations',
            self::Automation => 'Automation',
            self::Finance => 'Finance',
            self::Communications => 'Communications',
            self::System => 'System',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Executive => 5,
            self::PlatformHealth => 10,
            self::Operations => 20,
            self::Workforce => 30,
            self::Customers => 40,
            self::Automation => 50,
            self::Finance => 60,
            self::Communications => 70,
            self::System => 80,
        };
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, fn (self $a, self $b): int => $a->sortOrder() <=> $b->sortOrder());

        return $cases;
    }
}
