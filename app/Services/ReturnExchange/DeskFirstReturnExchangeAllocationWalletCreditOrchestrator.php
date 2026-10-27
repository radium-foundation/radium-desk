<?php

namespace App\Services\ReturnExchange;

use App\CentralWallet\Application\CentralWalletReturnExchangeLedgerCreditService;
use App\Models\OnlineReturnRefundAllocation;
use App\Models\OnlineReturnRefundAllocationLine;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxWalletRefundClient;
use App\Services\RdService\RdServiceInWalletRefundClient;
use App\Services\RdService\RdServiceNetWalletRefundClient;
use App\Services\Refunds\WalletRefundDestinationResolver;
use App\Support\Refunds\DeskFirstWalletRefundSpokePolicy;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Desk-first return-exchange wallet credit: authoritative Central Wallet credit on Desk, then spoke audit.
 */
final class DeskFirstReturnExchangeAllocationWalletCreditOrchestrator
{
    public function __construct(
        private readonly CentralWalletReturnExchangeLedgerCreditService $deskCredits,
        private readonly RdServiceInWalletRefundClient $rdServiceInWalletRefundClient,
        private readonly RdServiceNetWalletRefundClient $rdServiceNetWalletRefundClient,
        private readonly RadiumBoxWalletRefundClient $radiumBoxWalletRefundClient,
        private readonly WalletRefundDestinationResolver $destinations,
    ) {}

    public function isEnabled(): bool
    {
        return $this->deskCredits->isEnabled();
    }

    /**
     * @return array{provider: string, reference_number: string, transaction_id: string, metadata: array<string, mixed>}
     */
    public function execute(
        OnlineReturnRefundAllocation $allocation,
        OnlineReturnRefundAllocationLine $line,
        Order $order,
        User $actor,
    ): array {
        $orderId = trim((string) $order->order_id);
        $deskReference = trim($allocation->reference_no).'-L'.$line->line_no;
        $amount = (string) $line->amount;

        DeskFirstWalletRefundSpokePolicy::assertSpokeAllowedForDeskFirst($orderId, $this->destinations);

        $correlationId = (string) Str::uuid();

        try {
            $deskCredit = $this->deskCredits->creditForAllocationLine($order, $line, $deskReference, $correlationId);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'execution' => 'Central Wallet credit could not be posted: '.$exception->getMessage(),
            ]);
        }

        $ledgerEntryId = (int) $deskCredit['ledger_entry_id'];
        $walletReference = (string) $deskCredit['wallet_reference'];

        $spoke = $this->recordSpokeAudit($deskReference, $orderId, $amount, $ledgerEntryId, $order->customer_email);

        return [
            'provider' => 'desk_first_central_wallet',
            'reference_number' => $walletReference,
            'transaction_id' => (string) $ledgerEntryId,
            'metadata' => [
                'executed_by' => $actor->id,
                'allocation_line_id' => $line->id,
                'destination' => $line->destination->value,
                'desk_refund_reference' => $deskReference,
                'desk_first' => true,
                'central_wallet_ledger_entry_id' => $ledgerEntryId,
                'central_wallet_id' => $deskCredit['central_wallet_id'],
                'wallet_balance' => $deskCredit['available_balance'],
                'desk_ledger_idempotent_replay' => $deskCredit['idempotent_replay'],
                'spoke_audit' => $spoke,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordSpokeAudit(
        string $deskReference,
        string $orderId,
        string $amount,
        int $ledgerEntryId,
        ?string $email,
    ): array {
        if ($this->destinations->isRdServiceIn($orderId)) {
            if (! $this->rdServiceInWalletRefundClient->isConfigured()) {
                throw ValidationException::withMessages([
                    'execution' => 'rdservice.in wallet refund audit is not configured after Desk credit.',
                ]);
            }

            $credit = $this->rdServiceInWalletRefundClient->creditWalletRefund(
                deskRefundReference: $deskReference,
                orderId: $orderId,
                amount: $amount,
                customerEmail: $email,
                centralWalletLedgerEntryId: $ledgerEntryId,
            );

            return ['provider' => 'rdservice_in_wallet_audit', ...$credit];
        }

        if ($this->destinations->isRdServiceNet($orderId)) {
            if (! $this->rdServiceNetWalletRefundClient->isConfigured()) {
                throw ValidationException::withMessages([
                    'execution' => 'rdservice.net wallet refund audit is not configured after Desk credit.',
                ]);
            }

            $credit = $this->rdServiceNetWalletRefundClient->creditWalletRefund(
                deskRefundReference: $deskReference,
                orderId: $orderId,
                amount: $amount,
                customerEmail: $email,
                centralWalletLedgerEntryId: $ledgerEntryId,
            );

            return ['provider' => 'rdservice_net_central_wallet_audit', ...$credit];
        }

        if ($this->destinations->isRadiumBox($orderId)) {
            if (! $this->radiumBoxWalletRefundClient->isConfigured()) {
                throw ValidationException::withMessages([
                    'execution' => 'RadiumBox wallet refund audit is not configured after Desk credit.',
                ]);
            }

            $credit = $this->radiumBoxWalletRefundClient->creditWalletRefund(
                deskRefundReference: $deskReference,
                orderId: $orderId,
                amount: (float) $amount,
                customerEmail: $email,
                centralWalletLedgerEntryId: $ledgerEntryId,
            );

            return ['provider' => 'radiumbox_wallet_audit', ...$credit];
        }

        throw ValidationException::withMessages([
            'execution' => $this->destinations->unsupportedAutomatedWalletCreditMessage($orderId),
        ]);
    }
}
