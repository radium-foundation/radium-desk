<?php

namespace App\Enums;

enum TeamAvailabilityChangeSource: string
{
    case Manual = 'manual';
    case Login = 'login';
    case Logout = 'logout';
    case Timeout = 'timeout';
}
