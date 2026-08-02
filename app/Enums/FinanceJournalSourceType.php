<?php

namespace App\Enums;

enum FinanceJournalSourceType: string
{
    case Expense = 'expense';
    case OrderPayment = 'order_payment';
    case Refund = 'refund';
    case OpeningBalance = 'opening_balance';
    case ManualAdjustment = 'manual_adjustment';
    case CashDeposit = 'cash_deposit';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Expense',
            self::OrderPayment => 'Order Payment',
            self::Refund => 'Refund',
            self::OpeningBalance => 'Opening Balance',
            self::ManualAdjustment => 'Manual Adjustment',
            self::CashDeposit => 'Cash Deposit',
            self::BankTransfer => 'Bank Transfer',
        };
    }
}
