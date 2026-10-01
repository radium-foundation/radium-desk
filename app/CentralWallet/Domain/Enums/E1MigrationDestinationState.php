<?php

namespace App\CentralWallet\Domain\Enums;

enum E1MigrationDestinationState: string
{
    case Unverified = 'UNVERIFIED';
    case VerifiedIdentity = 'VERIFIED_IDENTITY';
    case CwidReady = 'CWID_READY';
    case LinkReady = 'LINK_READY';
    case MigrationDestinationReady = 'MIGRATION_DESTINATION_READY';
    case Ambiguous = 'AMBIGUOUS';

    public function isDestinationReady(): bool
    {
        return $this === self::MigrationDestinationReady;
    }
}
