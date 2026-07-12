<?php

namespace App\Enums;

enum CommunicationActionExecutionMode: string
{
    case Manual = 'manual';
    case SemiAutomatic = 'semi_automatic';
    case Automatic = 'automatic';
}
