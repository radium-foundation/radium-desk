<?php

namespace App\Services\ReturnExchange;

use App\Models\OnlineReturnRefundAllocation;
use App\Models\OnlineReturnRefundAllocationLine;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxWalletRefundClient;
use App\Services\RdService\RdServiceInWalletRefundClient;
use App\Services\RdService\RdServiceNetWalletRefundClient;
use App\Services\Refunds\WalletRefundDestinationResolver;
use App\Services\Refunds\WalletRefundExecutor;
use App\Support\Money\WalletMoney;
use App\Support\Refunds\DeskFirstWalletRefundSpokePolicy;
use App\Support\Refunds\DeskFirstWalletRefundSupport;
use Illuminate\Validation\ValidationException;

/**
 * Posts wallet credit for an allocation line using the same spoke clients as {@see WalletRefundExecutor}
 * without creating or completing a {@see RefundRequest}.
 */
final class ReturnExchangeAllocationWalletCreditService
{
    public function __construct(
        private readonly DeskFirstReturnExchangeAllocationWalletCreditOrchestrator $deskFirstOrchestrator,
        private readonly RdServiceInWalletRefundClient $rdServiceInWalletRefundClient,
        private readonly RdServiceNetWalletRefundClient $rdServiceNetWalletRefundClient,
        private readonly RadiumBoxWalletRefundClient $radiumBoxWalletRefundClient,
        private readonly WalletRefundDestinationResolver $destinations,
    ) {}

    /**
     * @return array{provider: string, reference_number: string, transaction_id: string, metadata: array<string, mixed>}
     */
    public function credit(
        OnlineReturnRefundAllocation $allocation,
        OnlineReturnRefundAllocationLine $line,
        Order $order,
        User $actor,
    ): array {
        $orderId = trim((string) $order->order_id);
        if ($orderId === '') {
            throw ValidationException::withMessages([
                'execution' => 'Order id is required before wallet credit can be posted.',
            ]);
        }

        $amount = WalletMoney::normalize($line->amount);
        if ($amount === null || ! WalletMoney::isPositive($amount)) {
            throw ValidationException::withMessages([
                'execution' => 'Allocation line amount must be positive for wallet credit.',
            ]);
        }

        $deskReference = $this->deskReference($allocation, $line);

        if (DeskFirstWalletRefundSupport::requiresAuthoritativeDeskLedger()
            || $this->deskFirstOrchestrator->isEnabled()) {
            if (! $this->deskFirstOrchestrator->isEnabled()) {
                throw ValidationException::withMessages([
                    'execution' => 'Desk-authoritative wallet credits require CENTRAL_WALLET_DESK_FIRST_WALLET_REFUND_ENABLED before execution.',
                ]);
            }

            if ($this->destinations->isRdServiceNet($orderId)
                && ! $this->destinations->supportsAutomatedWalletCredit($orderId)) {
                throw ValidationException::withMessages([
                    'execution' => $this->destinations->unsupportedAutomatedWalletCreditMessage($orderId),
                ]);
            }

            DeskFirstWalletRefundSpokePolicy::assertSpokeAllowedForDeskFirst($orderId, $this->destinations);

            return $this->deskFirstOrchestrator->execute($allocation, $line, $order, $actor);
        }

        if ($this->destinations->isRdServiceIn($orderId)) {
            return $this->creditRdServiceIn($deskReference, $orderId, $amount, $order, $actor, $line);
        }

        if ($this->destinations->isRdServiceNet($orderId)) {
            if (! $this->destinations->supportsAutomatedWalletCredit($orderId)) {
                throw ValidationException::withMessages([
                    'execution' => $this->destinations->unsupportedAutomatedWalletCreditMessage($orderId),
                ]);
            }

            return $this->creditRdServiceNet($deskReference, $orderId, $amount, $order, $actor, $line);
        }

        if ($this->destinations->isRadiumBox($orderId)) {
            return $this->creditRadiumBox($deskReference, $orderId, $amount, $order, $actor, $line);
        }

        throw ValidationException::withMessages([
            'execution' => $this->destinations->unsupportedAutomatedWalletCreditMessage($orderId),
        ]);
    }

    public function deskReference(OnlineReturnRefundAllocation $allocation, OnlineReturnRefundAllocationLine $line): string
    {
        return $allocation->reference_no.'-L'.$line->line_no;
    }

    /**
     * @return array{provider: string, reference_number: string, transaction_id: string, metadata: array<string, mixed>}
     */
    private function creditRdServiceIn(
        string $deskReference,
        string $orderId,
        string $amount,
        Order $order,
        User $actor,
        OnlineReturnRefundAllocationLine $line,
    ): array {
        if (! $this->rdServiceInWalletRefundClient->isConfigured()) {
            throw ValidationException::withMessages([
                'execution' => 'rdservice.in wallet credit is not configured.',
            ]);
        }

        $credit = $this->rdServiceInWalletRefundClient->creditWalletRefund(
            deskRefundReference: $deskReference,
            orderId: $orderId,
            amount: $amount,
            customerEmail: $order->customer_email,
        );

        return $this->result('rdservice_in_wallet', $credit['wallet_reference'], (string) $credit['wallet_transaction_id'], $actor, $line, [
            'wallet_balance' => $credit['balance'],
        ]);
    }

    /**
     * @return array{provider: string, reference_number: string, transaction_id: string, metadata: array<string, mixed>}
     */
    private function creditRdServiceNet(
        string $deskReference,
        string $orderId,
        string $amount,
        Order $order,
        User $actor,
        OnlineReturnRefundAllocationLine $line,
    ): array {
        if (! $this->rdServiceNetWalletRefundClient->isConfigured()) {
            throw ValidationException::withMessages([
                'execution' => 'rdservice.net wallet credit is not configured.',
            ]);
        }

        $credit = $this->rdServiceNetWalletRefundClient->creditWalletRefund(
            deskRefundReference: $deskReference,
            orderId: $orderId,
            amount: $amount,
            customerEmail: $order->customer_email,
        );

        return $this->result('rdservice_net_central_wallet', $credit['wallet_reference'], (string) $credit['wallet_transaction_id'], $actor, $line, [
            'wallet_balance' => $credit['balance'],
            'destination' => 'central_wallet',
        ]);
    }

    /**
     * @return array{provider: string, reference_number: string, transaction_id: string, metadata: array<string, mixed>}
     */
    private function creditRadiumBox(
        string $deskReference,
        string $orderId,
        string $amount,
        Order $order,
        User $actor,
        OnlineReturnRefundAllocationLine $line,
    ): array {
        if (! $this->radiumBoxWalletRefundClient->isConfigured()) {
            throw ValidationException::withMessages([
                'execution' => 'RadiumBox wallet credit is not configured.',
            ]);
        }

        $credit = $this->radiumBoxWalletRefundClient->creditWalletRefund(
            deskRefundReference: $deskReference,
            orderId: $orderId,
            amount: (float) $amount,
            customerEmail: $order->customer_email,
        );

        return $this->result('radiumbox_wallet', $credit['wallet_reference'], (string) $credit['wallet_transaction_id'], $actor, $line, [
            'wallet_balance' => $credit['balance'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{provider: string, reference_number: string, transaction_id: string, metadata: array<string, mixed>}
     */
    private function result(
        string $provider,
        string $reference,
        string $transactionId,
        User $actor,
        OnlineReturnRefundAllocationLine $line,
        array $extra,
    ): array {
        return [
            'provider' => $provider,
            'reference_number' => $reference,
            'transaction_id' => $transactionId,
            'metadata' => array_merge([
                'executed_by' => $actor->id,
                'allocation_line_id' => $line->id,
                'destination' => $line->destination->value,
            ], $extra),
        ];
    }
}
