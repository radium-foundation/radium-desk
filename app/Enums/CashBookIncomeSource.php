<?php

namespace App\Enums;

enum CashBookIncomeSource: string
{
    case RefurbishedDeviceSale = 'refurbished_device_sale';
    case CashSale = 'cash_sale';
    case Accessories = 'accessories';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RefurbishedDeviceSale => 'Refurbished Device Sale',
            self::CashSale => 'Cash Sale',
            self::Accessories => 'Accessories',
            self::Other => 'Other',
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
