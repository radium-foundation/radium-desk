<?php

namespace App\Enums;

enum IncomingEmailLearningRuleType: string
{
    case Sender = 'sender';
    case SenderDomain = 'sender_domain';
    case SubjectPattern = 'subject_pattern';
    case Mailbox = 'mailbox';
    case Keyword = 'keyword';

    public function label(): string
    {
        return match ($this) {
            self::Sender => 'Sender',
            self::SenderDomain => 'Sender Domain',
            self::SubjectPattern => 'Subject Pattern',
            self::Mailbox => 'Mailbox',
            self::Keyword => 'Keyword',
        };
    }
}
