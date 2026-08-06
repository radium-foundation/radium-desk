<?php

namespace App\Enums;

enum IncomingEmailDisposition: string
{
    case CreateCase = 'create_case';
    case LinkCase = 'link_case';
    case Ignore = 'ignore';
    case Spam = 'spam';
    case Promotion = 'promotion';
    case AutoProcessed = 'auto_processed';
    case KeepPending = 'keep_pending';

    public function label(): string
    {
        return match ($this) {
            self::CreateCase => 'Create Service Case',
            self::LinkCase => 'Link Existing Case',
            self::Ignore => 'Ignore',
            self::Spam => 'Spam',
            self::Promotion => 'Promotion',
            self::AutoProcessed => 'Completed Automatically',
            self::KeepPending => 'Keep Pending',
        };
    }

    public function leavesNeedsHuman(): bool
    {
        return $this !== self::KeepPending;
    }

    public function isTerminal(): bool
    {
        return $this->leavesNeedsHuman();
    }
}
