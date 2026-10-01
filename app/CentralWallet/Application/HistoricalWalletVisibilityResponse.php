<?php

namespace App\CentralWallet\Application;

final class HistoricalWalletVisibilityResponse
{
    /**
     * @param  array<string, mixed>  $legacy
     */
    public function __construct(
        public readonly int $status,
        public readonly array $legacy,
    ) {}

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function toHttpResult(): array
    {
        return [
            'status' => $this->status,
            'body' => $this->mergeUnifiedFields($this->legacy),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function mergeUnifiedFields(array $body): array
    {
        $currency = (string) ($body['currency'] ?? config('central_wallet.currency', 'INR'));

        if (($body['error'] ?? '') === 'use_trusted_identity_path') {
            return $body;
        }

        if (($body['identity_state'] ?? '') === 'provisional') {
            $balance = (string) ($body['available_balance'] ?? '0.00');
            $balanceSource = (string) ($body['balance_source'] ?? 'historical_wallet_refund');
            if ($balanceSource === 'local_spoke_wallet') {
                $balanceSource = 'historical_wallet_refund';
            }

            return array_merge($body, [
                'wallet_balance' => $balance,
                'balance_status' => 'unverified',
                'balance_source' => $balanceSource,
                'spendable' => false,
                'verification_required' => true,
                'currency' => $currency,
                'display_label' => 'Wallet Balance — Unverified',
                'display_hint' => 'Verify your account to use this balance.',
            ]);
        }

        if (($body['identity_state'] ?? '') === 'verified') {
            $balance = (string) ($body['available_balance'] ?? '0.00');

            return array_merge($body, [
                'wallet_balance' => $balance,
                'balance_status' => 'verified',
                'balance_source' => 'central_wallet',
                'spendable' => (bool) ($body['spendable'] ?? true),
                'verification_required' => false,
                'currency' => $currency,
                'display_label' => 'Wallet Balance',
            ]);
        }

        if (($body['identity_state'] ?? '') === 'provisional'
            && ($body['balance_source'] ?? '') === 'central_wallet') {
            $balance = (string) ($body['available_balance'] ?? '0.00');

            return array_merge($body, [
                'wallet_balance' => $balance,
                'balance_status' => 'unverified',
                'balance_source' => 'central_wallet',
                'spendable' => false,
                'verification_required' => true,
                'currency' => $currency,
                'display_label' => 'Wallet Balance — Unverified',
                'display_hint' => 'Verify your account to use this balance.',
            ]);
        }

        if (($body['identity_state'] ?? '') === 'unresolved' || ($body['error'] ?? '') === 'identity_ambiguous') {
            return array_merge($body, [
                'wallet_balance' => '0.00',
                'balance_status' => 'verification_required',
                'balance_source' => null,
                'spendable' => false,
                'verification_required' => true,
                'currency' => $currency,
            ]);
        }

        if (($body['error'] ?? '') === 'identity_unresolved') {
            return array_merge($body, [
                'wallet_balance' => '0.00',
                'balance_status' => 'verification_required',
                'balance_source' => null,
                'spendable' => false,
                'verification_required' => true,
                'currency' => $currency,
            ]);
        }

        return $body;
    }
}
