<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case AwaitingProductDetails = 'awaiting_product_details';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
            self::AwaitingProductDetails => 'Awaiting Product Details',
        };
    }

    /**
     * Service cases that should appear on the operational dashboard.
     *
     * @return list<self>
     */
    public static function operationallyActive(): array
    {
        return [
            self::Open,
            self::InProgress,
            self::AwaitingProductDetails,
        ];
    }
}
