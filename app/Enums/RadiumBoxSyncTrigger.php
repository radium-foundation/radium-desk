<?php

namespace App\Enums;

enum RadiumBoxSyncTrigger: string
{
    case Customer360Open = 'customer_360_open';
    case WorkspaceOpen = 'workspace_open';
    case BonvoiceLiveCallMatch = 'bonvoice_live_call_match';
    case OrderSearchMatch = 'order_search_match';

    public function label(): string
    {
        return match ($this) {
            self::Customer360Open => 'Customer 360 opened',
            self::WorkspaceOpen => 'Workspace opened',
            self::BonvoiceLiveCallMatch => 'BonVoice live call match',
            self::OrderSearchMatch => 'Order search match',
        };
    }
}
