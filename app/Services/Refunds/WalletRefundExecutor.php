<?php

namespace App\Services\Refunds;

use App\Contracts\Refunds\RefundExecutor;
use App\Enums\ApprovedRefundMethod;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxWalletRefundClient;
use App\Services\RdService\RdServiceInWalletRefundClient;
use App\Services\RdService\RdServiceNetWalletRefundClient;
use App\Support\Money\WalletMoney;
use Illuminate\Validation\ValidationException;

class WalletRefundExecutor implements RefundExecutor
{
    public function __construct(
        private readonly RdServiceInWalletRefundClient $rdServiceInWalletRefundClient,
        private readonly RdServiceNetWalletRefundClient $rdServiceNetWalletRefundClient,
        private readonly RadiumBoxWalletRefundClient $radiumBoxWalletRefundClient,
        private readonly WalletRefundDestinationResolver $destinations,
        private readonly WalletRefundExistingCreditDetector $existingCreditDetector,
    ) {}

    public function supports(ApprovedRefundMethod $method): bool
    {
        return $method === ApprovedRefundMethod::Wallet;
    }

    public function execute(RefundRequest $refund, User $actor, array $payload): array
    {
        $refund->loadMissing('order');

        $orderId = $refund->order?->order_id;
        if (! is_string($orderId) || trim($orderId) === '') {
            throw ValidationException::withMessages([
                'refund' => 'Refund order id is required before wallet credit can be posted.',
            ]);
        }

        $orderId = trim($orderId);
        $amount = WalletMoney::normalize($refund->refund_amount ?? $refund->amount);
        if ($amount === null || ! WalletMoney::isPositive($amount)) {
            throw ValidationException::withMessages([
                'refund' => 'A positive refund amount is required before wallet credit can be posted.',
            ]);
        }

        if ($this->destinations->isRdServiceIn($orderId)) {
            return $this->executeCentralWalletCredit(
                fn (): array => $this->creditRdServiceIn($refund, $actor, $payload, $orderId, $amount),
                $refund,
                $actor,
                $payload,
                'rdservice_in_wallet',
                'Wallet credited automatically via rdservice.in integration.',
            );
        }

        if ($this->destinations->isRdServiceNet($orderId)) {
            if (! $this->destinations->supportsAutomatedWalletCredit($orderId)) {
                throw ValidationException::withMessages([
                    'refund' => $this->destinations->unsupportedAutomatedWalletCreditMessage($orderId),
                ]);
            }

            return $this->executeCentralWalletCredit(
                fn (): array => $this->creditRdServiceNet($refund, $actor, $payload, $orderId, $amount),
                $refund,
                $actor,
                $payload,
                'rdservice_net_central_wallet',
                'Central Wallet credited automatically via rdservice.net integration.',
            );
        }

        if ($this->destinations->isRadiumBox($orderId)) {
            return $this->executeCentralWalletCredit(
                fn (): array => $this->creditRadiumBox($refund, $actor, $payload, $orderId, $amount),
                $refund,
                $actor,
                $payload,
                'radiumbox_wallet',
                'Wallet credited automatically via RadiumBox integration.',
            );
        }

        throw ValidationException::withMessages([
            'refund' => $this->destinations->unsupportedAutomatedWalletCreditMessage($orderId),
        ]);
    }

    /**
     * @param  callable(): array{provider: string, reference_number: string|null, transaction_id: string|null, remarks: string|null, metadata: array<string, mixed>}  $creditExecutor
     * @param  array{remarks?: string|null}  $payload
     * @return array{provider: string, reference_number: string|null, transaction_id: string|null, remarks: string|null, metadata: array<string, mixed>}
     */
    private function executeCentralWalletCredit(
        callable $creditExecutor,
        RefundRequest $refund,
        User $actor,
        array $payload,
        string $provider,
        string $defaultRemark,
    ): array {
        $detection = $this->existingCreditDetector->detect($refund);

        if ($detection->isMatched()) {
            $entry = $detection->ledgerEntry();
            if ($entry === null) {
                throw ValidationException::withMessages([
                    'refund' => 'Central Wallet credit reconciliation failed unexpectedly.',
                ]);
            }

            return $this->executionResultFromExistingCredit(
                provider: $provider,
                entryId: (int) $entry->id,
                centralWalletId: (string) $entry->central_wallet_id,
                refund: $refund,
                actor: $actor,
                payload: $payload,
                defaultRemark: 'Refund completed from existing Central Wallet credit (CW:'.$entry->id.').',
            );
        }

        if ($detection->isBlocking()) {
            throw ValidationException::withMessages([
                'refund' => $detection->adminMessage()
                    ?? 'Central Wallet credit reconciliation could not proceed safely.',
            ]);
        }

        return $creditExecutor();
    }

    /**
     * @param  array{remarks?: string|null}  $payload
     * @return array{provider: string, reference_number: string, transaction_id: string, remarks: string, metadata: array<string, mixed>}
     */
    private function executionResultFromExistingCredit(
        string $provider,
        int $entryId,
        string $centralWalletId,
        RefundRequest $refund,
        User $actor,
        array $payload,
        string $defaultRemark,
    ): array {
        $walletReference = 'CW:'.$entryId;

        return $this->executionResult(
            provider: $provider,
            walletReference: $walletReference,
            walletTransactionId: (string) $entryId,
            refund: $refund,
            actor: $actor,
            payload: $payload,
            defaultRemark: $defaultRemark,
            extra: [
                'recovered_from_existing_credit' => true,
                'central_wallet_id' => $centralWalletId,
                'ledger_entry_id' => $entryId,
            ],
        );
    }

    /**
     * @param  array{remarks?: string|null}  $payload
     * @return array{provider: string, reference_number: string|null, transaction_id: string|null, remarks: string|null, metadata: array<string, mixed>}
     */
    private function creditRdServiceIn(
        RefundRequest $refund,
        User $actor,
        array $payload,
        string $orderId,
        string $amount,
    ): array {
        if (! $this->rdServiceInWalletRefundClient->isConfigured()) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in wallet credit is not configured. Wallet refunds cannot fall back to manual completion.',
            ]);
        }

        $credit = $this->rdServiceInWalletRefundClient->creditWalletRefund(
            deskRefundReference: (string) $refund->reference_no,
            orderId: $orderId,
            amount: $amount,
            customerEmail: $refund->order?->customer_email,
        );

        return $this->executionResult(
            provider: 'rdservice_in_wallet',
            walletReference: $credit['wallet_reference'],
            walletTransactionId: (string) $credit['wallet_transaction_id'],
            refund: $refund,
            actor: $actor,
            payload: $payload,
            defaultRemark: 'Wallet credited automatically via rdservice.in integration.',
            extra: ['wallet_balance' => $credit['balance']],
        );
    }

    /**
     * @param  array{remarks?: string|null}  $payload
     * @return array{provider: string, reference_number: string|null, transaction_id: string|null, remarks: string|null, metadata: array<string, mixed>}
     */
    private function creditRdServiceNet(
        RefundRequest $refund,
        User $actor,
        array $payload,
        string $orderId,
        string $amount,
    ): array {
        if (! $this->rdServiceNetWalletRefundClient->isConfigured()) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.net wallet credit is not configured. Wallet refunds cannot fall back to manual completion.',
            ]);
        }

        $credit = $this->rdServiceNetWalletRefundClient->creditWalletRefund(
            deskRefundReference: (string) $refund->reference_no,
            orderId: $orderId,
            amount: $amount,
            customerEmail: $refund->order?->customer_email,
        );

        return $this->executionResult(
            provider: 'rdservice_net_central_wallet',
            walletReference: $credit['wallet_reference'],
            walletTransactionId: (string) $credit['wallet_transaction_id'],
            refund: $refund,
            actor: $actor,
            payload: $payload,
            defaultRemark: 'Central Wallet credited automatically via rdservice.net integration.',
            extra: ['wallet_balance' => $credit['balance'], 'destination' => 'central_wallet'],
        );
    }

    /**
     * @param  array{remarks?: string|null}  $payload
     * @return array{provider: string, reference_number: string|null, transaction_id: string|null, remarks: string|null, metadata: array<string, mixed>}
     */
    private function creditRadiumBox(
        RefundRequest $refund,
        User $actor,
        array $payload,
        string $orderId,
        string $amount,
    ): array {
        if (! $this->radiumBoxWalletRefundClient->isConfigured()) {
            throw ValidationException::withMessages([
                'refund' => 'RadiumBox wallet credit is not configured. Wallet refunds cannot fall back to manual completion.',
            ]);
        }

        $credit = $this->radiumBoxWalletRefundClient->creditWalletRefund(
            deskRefundReference: (string) $refund->reference_no,
            orderId: $orderId,
            amount: (float) $amount,
            customerEmail: $refund->order?->customer_email,
        );

        return $this->executionResult(
            provider: 'radiumbox_wallet',
            walletReference: $credit['wallet_reference'],
            walletTransactionId: (string) $credit['wallet_transaction_id'],
            refund: $refund,
            actor: $actor,
            payload: $payload,
            defaultRemark: 'Wallet credited automatically via RadiumBox integration.',
            extra: ['wallet_balance' => $credit['balance']],
        );
    }

    /**
     * @param  array{remarks?: string|null}  $payload
     * @param  array<string, mixed>  $extra
     * @return array{provider: string, reference_number: string, transaction_id: string, remarks: string, metadata: array<string, mixed>}
     */
    private function executionResult(
        string $provider,
        string $walletReference,
        string $walletTransactionId,
        RefundRequest $refund,
        User $actor,
        array $payload,
        string $defaultRemark,
        array $extra,
    ): array {
        $remarks = trim((string) ($payload['remarks'] ?? ''));

        return [
            'provider' => $provider,
            'reference_number' => $walletReference,
            'transaction_id' => $walletTransactionId,
            'remarks' => $remarks !== '' ? $remarks : $defaultRemark,
            'metadata' => array_merge([
                'method' => $refund->approved_refund_method?->value,
                'executed_by' => $actor->id,
                'desk_refund_reference' => $refund->reference_no,
            ], $extra),
        ];
    }
}
