<?php

namespace App\Enums;

enum WorkspaceContext: string
{
    case Dashboard = 'dashboard';
    case ServiceCase = 'service_case';
    case Order = 'order';
    case Customer = 'customer';
    case Mobile = 'mobile';
    case Api = 'api';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::ServiceCase => 'Service Case',
            self::Order => 'Order',
            self::Customer => 'Customer',
            self::Mobile => 'Mobile',
            self::Api => 'API',
            self::Ai => 'AI',
        };
    }

    public function isInteractive(): bool
    {
        return ! in_array($this, [self::Api], true);
    }

    public function refreshProfile(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
