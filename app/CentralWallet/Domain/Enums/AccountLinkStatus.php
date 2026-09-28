<?php

namespace App\CentralWallet\Domain\Enums;

enum AccountLinkStatus: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Revoked = 'revoked';
    case Suspended = 'suspended';
}
