<?php

namespace App\Services\ReturnExchange;

use App\Enums\OnlineReturnRefundAllocationDestination;
use App\Enums\OnlineReturnRefundAllocationLineExecutionStatus;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\OnlineReturnRefundAllocationLine;
use App\Models\OnlineReturnRefundAllocationLineExecution;
use App\Models\OnlineReturnRefundAllocationOpmConversion;
use App\Models\Order;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmSiteAdapterResolver;
use App\Support\Money\WalletMoney;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ReturnExchangeAllocationOpmConversionEligibilityService
{
    public function __construct(
        private readonly WalletRefundOpmSiteAdapterResolver $adapterResolver,
        private readonly ReturnExchangeAllocationOpmCreditSourceResolver $creditSources,
        private readonly ReturnExchangeAllocationWalletCreditService $walletReferences,
    ) {}

    public function isConversionEnabled(): bool
    {
        return (bool) config('return_exchange.online_refund_allocation.opm_conversion_enabled', false);
    }

    public function assertCanRequest(
        OnlineReturnRefundAllocationLine $line,
        OnlineReturnRefundAllocationLineExecution $execution,
        string $amount,
        ?int $excludeConversionId = null,
    ): void {
        if (! $this->isConversionEnabled()) {
            throw ValidationException::withMessages([
                'conversion' => 'Return-case allocation wallet-to-OPM conversion is disabled in this environment.',
            ]);
        }

        $line->loadMissing(['allocation.order', 'allocation.returnCase']);
        $allocation = $line->allocation;
        if ($allocation === null) {
            throw ValidationException::withMessages(['line' => 'Allocation is missing.']);
        }

        if ($line->destination !== OnlineReturnRefundAllocationDestination::Wallet) {
            throw ValidationException::withMessages([
                'conversion' => 'Wallet-to-OPM conversion applies only to wallet-credited allocation lines.',
            ]);
        }

        if ($execution->status !== OnlineReturnRefundAllocationLineExecutionStatus::Succeeded) {
            throw ValidationException::withMessages([
                'execution' => 'Only a succeeded wallet credit execution can be converted to OPM.',
            ]);
        }

        $ambiguousReservation = OnlineReturnRefundAllocationOpmConversion::query()
            ->where('online_return_refund_allocation_line_execution_id', $execution->id)
            ->when($excludeConversionId !== null, fn ($q) => $q->where('id', '!=', $excludeConversionId))
            ->where('failure_code', 'reservation_ambiguous')
            ->where('status', WalletRefundOpmConversionStatus::ReconciliationHold)
            ->exists();
        if ($ambiguousReservation) {
            throw ValidationException::withMessages([
                'conversion' => 'A prior conversion for this wallet credit is awaiting reservation reconciliation.',
            ]);
        }

        if ((int) $execution->online_return_refund_allocation_line_id !== (int) $line->id) {
            throw ValidationException::withMessages([
                'execution' => 'Execution does not belong to this allocation line.',
            ]);
        }

        $order = $allocation->order;
        if (! $order instanceof Order) {
            throw ValidationException::withMessages([
                'order' => 'Order is required for wallet-to-OPM conversion.',
            ]);
        }

        $creditSource = $this->creditSources->resolveFromExecution($execution);
        $adapter = $this->adapterResolver->forOrderAndCreditSource($order->order_id, $creditSource);
        if (! $adapter->isEnabled()) {
            throw ValidationException::withMessages([
                'conversion' => 'Wallet-to-OPM conversion is not enabled for this site.',
            ]);
        }

        $normalized = WalletMoney::normalize($amount);
        if ($normalized === null || ! WalletMoney::isPositive($normalized)) {
            throw ValidationException::withMessages([
                'amount' => 'Conversion amount must be positive.',
            ]);
        }

        $remaining = $this->unconvertedWalletCreditAmount($execution, $excludeConversionId);
        if (bccomp($normalized, $remaining, 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'Conversion amount cannot exceed the unconverted wallet credit (₹'.$remaining.').',
            ]);
        }

    }

    public function unconvertedWalletCreditAmount(
        OnlineReturnRefundAllocationLineExecution $execution,
        ?int $excludeConversionId = null,
    ): string {
        $execution->loadMissing('line');
        $line = $execution->line;
        $credited = WalletMoney::normalize((string) ($line?->amount ?? '0')) ?? '0.00';

        if (! Schema::hasTable('online_return_refund_allocation_opm_conversions')) {
            return $credited;
        }

        $committed = OnlineReturnRefundAllocationOpmConversion::query()
            ->where('online_return_refund_allocation_line_execution_id', $execution->id)
            ->when($excludeConversionId !== null, fn ($q) => $q->where('id', '!=', $excludeConversionId))
            ->get()
            ->filter(fn (OnlineReturnRefundAllocationOpmConversion $row) => $row->status->countsTowardOrderRefundLimit())
            ->sum(fn (OnlineReturnRefundAllocationOpmConversion $row) => (float) $row->amount);

        $remaining = bcsub($credited, number_format($committed, 2, '.', ''), 2);

        return bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining;
    }

    public function siteCodeForExecution(OnlineReturnRefundAllocationLineExecution $execution, Order $order): string
    {
        $source = $this->creditSources->resolveFromExecution($execution);

        return $this->adapterResolver->forOrderAndCreditSource($order->order_id, $source)->siteCode();
    }
}
