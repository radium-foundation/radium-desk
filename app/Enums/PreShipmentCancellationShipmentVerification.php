<?php

namespace App\Enums;

enum PreShipmentCancellationShipmentVerification: string
{
    case ConfirmedUnshipped = 'confirmed_unshipped';
    case UnknownHeld = 'unknown_held';
}
