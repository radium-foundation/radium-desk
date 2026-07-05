<?php

namespace App\Enums;

enum IraRiskCategory: string
{
    case Workload = 'workload';
    case Customer = 'customer';
    case Team = 'team';

    public function label(): string
    {
        return match ($this) {
            self::Workload => 'Workload',
            self::Customer => 'Customer',
            self::Team => 'Team',
        };
    }
}
