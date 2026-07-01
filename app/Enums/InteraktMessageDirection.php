<?php

namespace App\Enums;

enum InteraktMessageDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
}
