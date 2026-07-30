<?php

namespace App\Enums;

enum ConversationQuestionKey: string
{
    case CustomerName = 'customer_name';
    case CustomerNeed = 'customer_need';
    case Brand = 'brand';
    case Model = 'model';
    case OrderId = 'order_id';
    case City = 'city';
    case Email = 'email';
    case Whatsapp = 'whatsapp';
    case AgentNotes = 'agent_notes';
    case Disposition = 'disposition';
    case NextAction = 'next_action';

    public function isMandatoryLive(): bool
    {
        return match ($this) {
            self::CustomerName, self::CustomerNeed => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CustomerName => 'Name',
            self::CustomerNeed => 'Need',
            self::Brand => 'Brand',
            self::Model => 'Model',
            self::OrderId => 'Order ID',
            self::City => 'City',
            self::Email => 'Email',
            self::Whatsapp => 'WhatsApp',
            self::AgentNotes => 'Agent Notes',
            self::Disposition => 'Disposition',
            self::NextAction => 'Next Action',
        };
    }
}
