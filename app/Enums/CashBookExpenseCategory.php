<?php

namespace App\Enums;

enum CashBookExpenseCategory: string
{
    case FloorCleaner = 'floor_cleaner';
    case Courier = 'courier';
    case Tea = 'tea';
    case Porter = 'porter';
    case Petrol = 'petrol';
    case Stationery = 'stationery';
    case Packing = 'packing';
    case Miscellaneous = 'miscellaneous';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FloorCleaner => 'Floor Cleaner',
            self::Courier => 'Courier',
            self::Tea => 'Tea',
            self::Porter => 'Porter',
            self::Petrol => 'Petrol',
            self::Stationery => 'Stationery',
            self::Packing => 'Packing',
            self::Miscellaneous => 'Miscellaneous',
            self::Other => 'Other',
        };
    }

    /**
     * Map operational category → chart-of-accounts expense code.
     */
    public function glAccountCode(): string
    {
        return match ($this) {
            self::Courier => '6001',
            self::FloorCleaner, self::Tea, self::Stationery, self::Packing => '6002',
            self::Porter, self::Petrol => '6005',
            self::Miscellaneous, self::Other => '6099',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
