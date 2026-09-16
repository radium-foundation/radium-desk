<?php

namespace App\Services\Refunds;

use App\Contracts\Refunds\RefundExecutor;
use App\Enums\ApprovedRefundMethod;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxWalletRefundClient;
use App\Services\RdService\RdServiceInWalletRefundClient;
use App\Support\Money\WalletMoney;
use Illuminate\Validation\ValidationException;

class WalletRefundExecutor implements RefundExecutor
{
    public function __construct(
        private readonly RdServiceInWalletRefundClient $rdServiceInWalletRefundClient,
        private readonly RadiumBoxWalletRefundClient $radiumBoxWalletRefundClient,
        private readonly WalletRefundDestinationResolver $destinations,
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
            return $this->creditRdServiceIn($refund, $actor, $payload, $orderId, $amount);
        }

        if ($this->destinations->isRadiumBox($orderId)) {
            return $this->creditRadiumBox($refund, $actor, $payload, $orderId, $amount);
        }

        throw ValidationException::withMessages([
            'refund' => 'No wallet credit destination exists for this order source.',
        ]);
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
