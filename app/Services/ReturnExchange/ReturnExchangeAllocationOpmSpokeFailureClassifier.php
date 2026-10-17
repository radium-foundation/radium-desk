<?php

namespace App\Services\ReturnExchange;

/**
 * Conservative spoke failure classification for allocation OPM flows.
 * Any failure that may have reached the site after a request was dispatched is treated as ambiguous.
 */
final class ReturnExchangeAllocationOpmSpokeFailureClassifier
{
    public function isDefinitiveClientFailure(string $message): bool
    {
        $normalized = strtolower($message);

        $patterns = [
            'site integration is not configured',
            'must use https',
            'site integration base url',
            'wallet-to-opm conversion is disabled',
            'not enabled for this site',
            'conversion is disabled in this environment',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function isDefinitiveSpokeRejection(string $message, string $operation): bool
    {
        if ($this->isDefinitiveClientFailure($message)) {
            return true;
        }

        $normalized = strtolower($message);

        $patterns = match ($operation) {
            'reserve' => [
                'invalid reservation request',
                'insufficient spendable wallet balance',
                'original wallet refund credit was not found',
                'only full refund conversion amounts',
                'legacy local wallet',
                'wallet opm conversion adapter is disabled',
            ],
            'confirm' => [
                'reservation not found',
            ],
            'finalize' => [
                'reservation not found',
                'reservation is not in a reversible state',
                'idempotency_key is invalid',
            ],
            'release' => [
                'reservation not found',
                'cannot release a finalized reservation',
            ],
            default => [],
        };

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function isAmbiguousSpokeFailure(string $message): bool
    {
        if ($this->isDefinitiveClientFailure($message)) {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'unreachable')
            || str_contains($normalized, 'connection')
            || str_contains($normalized, 'timeout')
            || str_contains($normalized, 'timed out')
            || str_contains($normalized, 'site adapter rejected');
    }

    public function shouldTreatAsAmbiguous(string $message, string $operation, bool $requestDispatched): bool
    {
        if (! $requestDispatched) {
            return $this->isAmbiguousSpokeFailure($message);
        }

        if ($this->isDefinitiveSpokeRejection($message, $operation)) {
            return false;
        }

        return true;
    }
}
