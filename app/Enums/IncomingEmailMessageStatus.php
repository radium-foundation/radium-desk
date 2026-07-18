<?php

namespace App\Enums;

enum IncomingEmailMessageStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Linked = 'linked';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
