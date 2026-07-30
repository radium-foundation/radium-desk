<?php

namespace App\Enums;

enum ConversationNextAction: string
{
    case FollowUp = 'follow_up';
    case ShareQuote = 'share_quote';
    case ShareCoupon = 'share_coupon';
    case CallTomorrow = 'call_tomorrow';
    case WaitingCustomer = 'waiting_customer';
    case Converted = 'converted';
    case NotInterested = 'not_interested';
    case ExistingOrder = 'existing_order';
    case Other = 'other';

    public function label(): string
    {
        return config(
            "conversation_workspace.next_actions.{$this->value}",
            str($this->value)->headline()->toString(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
