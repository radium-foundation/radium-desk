<?php

namespace App\CentralWallet\Domain\Enums;

enum E2SettlementDestinationState: string
{
    case Unverified = 'UNVERIFIED';
    case VerifiedIdentity = 'VERIFIED_IDENTITY';
    case CwidReady = 'CWID_READY';
    case LinkReady = 'LINK_READY';
    case SettlementDestinationReady = 'SETTLEMENT_DESTINATION_READY';
    case Ambiguous = 'AMBIGUOUS';
}
