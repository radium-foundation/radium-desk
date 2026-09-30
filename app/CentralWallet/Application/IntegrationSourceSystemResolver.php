<?php

namespace App\CentralWallet\Application;

use InvalidArgumentException;

/**
 * Derives authoritative source_system from authenticated integration identity.
 */
final class IntegrationSourceSystemResolver
{
    private const INTERNAL_CALLER = 'central_wallet_service';

    public function resolveAuthoritative(string $callerId, ?string $requestedSourceSystem): string
    {
        $requested = trim((string) $requestedSourceSystem);

        if ($callerId !== self::INTERNAL_CALLER) {
            if ($requested !== '' && $requested !== $callerId) {
                throw new InvalidArgumentException('source_system does not match authenticated site identity.');
            }

            return $callerId;
        }

        if ($requested === '') {
            throw new InvalidArgumentException('source_system is required for internal Central Wallet calls.');
        }

        return $requested;
    }
}
