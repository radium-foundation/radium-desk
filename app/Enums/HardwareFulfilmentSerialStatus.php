<?php

namespace App\Enums;

enum HardwareFulfilmentSerialStatus: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case Allocated = 'allocated';
}
