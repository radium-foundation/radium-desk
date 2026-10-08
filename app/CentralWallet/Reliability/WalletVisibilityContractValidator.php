<?php

namespace App\CentralWallet\Reliability;

use InvalidArgumentException;

final class WalletVisibilityContractValidator
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function validateSuccessBody(array $body): void
    {
        foreach ([
            'wallet_balance',
            'available_balance',
            'currency',
            'balance_status',
            'balance_source',
            'spendable',
            'verification_required',
        ] as $field) {
            if (! array_key_exists($field, $body)) {
                throw new InvalidArgumentException("wallet-visibility success body missing {$field}");
            }
        }

        $status = (string) $body['balance_status'];
        if (! in_array($status, ['verified', 'unverified', 'verification_required'], true)) {
            throw new InvalidArgumentException("wallet-visibility invalid balance_status: {$status}");
        }

        if (! is_bool($body['spendable'])) {
            throw new InvalidArgumentException('wallet-visibility spendable must be boolean');
        }

        if (! is_bool($body['verification_required'])) {
            throw new InvalidArgumentException('wallet-visibility verification_required must be boolean');
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function validateErrorBody(int $status, array $body): void
    {
        $expected = match ($status) {
            403 => 'site_mismatch',
            404 => 'not_found',
            409 => 'identity_ambiguous',
            422 => 'contact_data_required',
            503 => 'historical_wallet_visibility_disabled',
            default => null,
        };

        if ($expected === null) {
            return;
        }

        if ((string) ($body['error'] ?? '') !== $expected) {
            throw new InvalidArgumentException("wallet-visibility {$status} expected error {$expected}");
        }
    }

    public function isAuthoritativeZero(array $body): bool
    {
        $balance = (string) ($body['wallet_balance'] ?? $body['available_balance'] ?? '');
        $status = (string) ($body['balance_status'] ?? '');

        return bccomp($balance, '0', 2) === 0
            && $status === 'verification_required';
    }
}
