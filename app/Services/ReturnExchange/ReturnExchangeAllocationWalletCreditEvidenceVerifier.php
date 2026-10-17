<?php

namespace App\Services\ReturnExchange;

use App\Models\OnlineReturnRefundAllocation;
use App\Models\OnlineReturnRefundAllocationLine;
use App\Models\OnlineReturnRefundAllocationLineExecution;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use App\Models\Order;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmSiteAdapterResolver;
use App\Support\Money\WalletMoney;
use Illuminate\Validation\ValidationException;

/**
 * Binds OPM conversion to the exact wallet credit posted for an allocation line execution.
 *
 * Spoke reversal on RadiumBox matches the original credit by desk_refund_reference on the wallet row
 * (the same string passed at wallet credit time), not RefundRequest.reference_no.
 * Execution stores provider wallet reference in execution_reference and provider txn id in transaction_id.
 */
final class ReturnExchangeAllocationWalletCreditEvidenceVerifier
{
    public function __construct(
        private readonly ReturnExchangeAllocationWalletCreditService $walletReferences,
        private readonly ReturnExchangeAllocationOpmCreditSourceResolver $creditSources,
        private readonly WalletRefundOpmSiteAdapterResolver $adapterResolver,
    ) {}

    /**
     * @return array{
     *     desk_wallet_reference: string,
     *     bound_order_id: string,
     *     bound_wallet_credit_amount: string,
     *     site_code: string,
     * }
     */
    public function verifyAndSnapshot(
        OnlineReturnRefundAllocationLine $line,
        OnlineReturnRefundAllocationLineExecution $execution,
    ): array {
        $line->loadMissing(['allocation.order']);
        $allocation = $line->allocation;
        if ($allocation === null) {
            throw ValidationException::withMessages(['line' => 'Allocation is missing.']);
        }

        $order = $allocation->order;
        if (! $order instanceof Order) {
            throw ValidationException::withMessages(['order' => 'Order is required.']);
        }

        $orderId = trim((string) $order->order_id);
        if ($orderId === '') {
            throw ValidationException::withMessages(['order' => 'Order id is required.']);
        }

        $deskRef = $this->walletReferences->deskReference($allocation, $line);
        $creditAmount = WalletMoney::normalize((string) $line->amount);
        if ($creditAmount === null || ! WalletMoney::isPositive($creditAmount)) {
            throw ValidationException::withMessages([
                'execution' => 'Wallet credit line amount is invalid.',
            ]);
        }

        $providerRef = trim((string) $execution->execution_reference);
        $txnId = trim((string) $execution->transaction_id);
        if ($providerRef === '' || $txnId === '') {
            throw ValidationException::withMessages([
                'execution' => 'Wallet credit execution must include provider reference and transaction id evidence.',
            ]);
        }

        $creditSource = $this->creditSources->resolveFromExecution($execution);
        $siteCode = $this->adapterResolver->forOrderAndCreditSource($orderId, $creditSource)->siteCode();
        $expectedProvider = trim((string) $execution->executor_provider);
        if ($expectedProvider === '') {
            throw ValidationException::withMessages([
                'execution' => 'Wallet executor provider is missing on the credit execution.',
            ]);
        }

        return [
            'desk_wallet_reference' => $deskRef,
            'bound_order_id' => $orderId,
            'bound_wallet_credit_amount' => $creditAmount,
            'site_code' => $siteCode,
            'wallet_executor_provider' => $expectedProvider,
            'wallet_execution_reference' => $providerRef,
            'wallet_transaction_id' => $txnId,
            'wallet_credit_source' => $creditSource,
        ];
    }

    public function assertConversionMatchesExecution(
        OnlineReturnRefundAllocationOpmConversion $conversion,
        OnlineReturnRefundAllocationLine $line,
        OnlineReturnRefundAllocationLineExecution $execution,
    ): void {
        $snapshot = $this->verifyAndSnapshot($line, $execution);

        if ($conversion->desk_wallet_reference !== $snapshot['desk_wallet_reference']) {
            throw ValidationException::withMessages([
                'conversion' => 'Conversion desk wallet reference does not match the allocation wallet credit.',
            ]);
        }

        if ($conversion->bound_order_id !== null && $conversion->bound_order_id !== $snapshot['bound_order_id']) {
            throw ValidationException::withMessages([
                'conversion' => 'Conversion order binding does not match the allocation order.',
            ]);
        }

        if ($conversion->wallet_executor_provider !== $snapshot['wallet_executor_provider']) {
            throw ValidationException::withMessages([
                'conversion' => 'Conversion wallet source does not match the credit execution provider.',
            ]);
        }

        if ($conversion->wallet_execution_reference !== $snapshot['wallet_execution_reference']
            || $conversion->wallet_transaction_id !== $snapshot['wallet_transaction_id']) {
            throw ValidationException::withMessages([
                'conversion' => 'Conversion wallet evidence does not match the succeeded credit execution.',
            ]);
        }

        if ($conversion->site_code !== $snapshot['site_code']) {
            throw ValidationException::withMessages([
                'conversion' => 'Conversion site does not match the order wallet source.',
            ]);
        }
    }
}
