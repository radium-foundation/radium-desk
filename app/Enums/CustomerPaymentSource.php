<?php

namespace App\Enums;

enum CustomerPaymentSource: string
{
    case FinanceReceipt = 'finance_receipt';
    case HistoricalPosBackfill = 'historical_pos_backfill';

    public function label(): string
    {
        return match ($this) {
            self::FinanceReceipt => 'Finance payment receipt',
            self::HistoricalPosBackfill => 'Historical POS backfill',
        };
    }
}
