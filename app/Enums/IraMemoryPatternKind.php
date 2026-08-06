<?php

namespace App\Enums;

enum IraMemoryPatternKind: string
{
    case Sender = 'sender';
    case SenderDomain = 'sender_domain';
    case SubjectPattern = 'subject_pattern';
    case Mailbox = 'mailbox';
    case Keyword = 'keyword';
    case CustomerKey = 'customer_key';
    case OrderPattern = 'order_pattern';
    case ChannelThread = 'channel_thread';

    public function label(): string
    {
        return match ($this) {
            self::Sender => 'Sender',
            self::SenderDomain => 'Sender Domain',
            self::SubjectPattern => 'Subject Pattern',
            self::Mailbox => 'Mailbox',
            self::Keyword => 'Keyword',
            self::CustomerKey => 'Customer Key',
            self::OrderPattern => 'Order Pattern',
            self::ChannelThread => 'Channel Thread',
        };
    }
}
