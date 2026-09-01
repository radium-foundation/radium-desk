<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case StockIn = 'stock_in';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Reserve = 'reserve';
    case Unreserve = 'unreserve';
    case Sale = 'sale';
    case SaleCancel = 'sale_cancel';
    case Return = 'return';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::StockIn => 'Stock in',
            self::TransferOut => 'Transfer out',
            self::TransferIn => 'Transfer in',
            self::Reserve => 'Reserve',
            self::Unreserve => 'Release reservation',
            self::Sale => 'Sale',
            self::SaleCancel => 'Sale cancelled',
            self::Return => 'Return',
            self::Adjustment => 'Adjustment',
        };
    }
}
