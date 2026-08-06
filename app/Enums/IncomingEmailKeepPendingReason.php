<?php

namespace App\Enums;

enum IncomingEmailKeepPendingReason: string
{
    case WaitingCustomer = 'waiting_customer';
    case NeedManager = 'need_manager';
    case NeedOrderNumber = 'need_order_number';
    case NeedInvestigation = 'need_investigation';

    public function label(): string
    {
        return match ($this) {
            self::WaitingCustomer => 'Waiting Customer',
            self::NeedManager => 'Need Manager',
            self::NeedOrderNumber => 'Need Order Number',
            self::NeedInvestigation => 'Need Investigation',
        };
    }
}
