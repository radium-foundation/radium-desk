<?php

namespace App\Enums;

enum ChannelIngestOutcome: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
    case Unauthorized = 'unauthorized';
    case Replay = 'replay';
    case Conflict = 'conflict';
    case Failed = 'failed';
}
