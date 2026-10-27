<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case StockIn = 'stock_in';
    case Opening = 'opening';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Reserve = 'reserve';
    case Unreserve = 'unreserve';
    case Sale = 'sale';
    case SaleCancel = 'sale_cancel';
    case Return = 'return';
    case ReturnCaseReceive = 'return_case_receive';
    case ReturnCaseInspect = 'return_case_inspect';
    case ReturnCaseSerialReplace = 'return_case_serial_replace';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::StockIn => 'Stock in',
            self::Opening => 'Opening inventory',
            self::TransferOut => 'Transfer out',
            self::TransferIn => 'Transfer in',
            self::Reserve => 'Reserve',
            self::Unreserve => 'Release reservation',
            self::Sale => 'Sale',
            self::SaleCancel => 'Sale cancelled',
            self::Return => 'Return',
            self::ReturnCaseReceive => 'Return case receipt',
            self::ReturnCaseInspect => 'Return case inspection',
            self::ReturnCaseSerialReplace => 'Return case serial replacement',
            self::Adjustment => 'Adjustment',
        };
    }
}
