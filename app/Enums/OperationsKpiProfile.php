<?php

namespace App\Enums;

enum OperationsKpiProfile: string
{
    case Support = 'support';
    case Activation = 'activation';

    public function outcomeLabel(): string
    {
        return match ($this) {
            self::Support => 'Cases Worked',
            self::Activation => 'Orders Activated',
        };
    }

    public function effortLabel(): string
    {
        return match ($this) {
            self::Support => 'Customer Touches',
            self::Activation => 'Activation Sessions',
        };
    }
}
