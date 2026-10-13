<?php

namespace App\Services\Refunds;

use App\CentralWallet\Application\CentralWalletRefundLedgerReversalService;
use App\Models\RefundRequest;
use App\Services\RdService\RdServiceInWalletRefundReversalClient;
use App\Support\Money\WalletMoney;
use App\Support\Refunds\DeskFirstWalletRefundSupport;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Desk-first revoke: CW ledger debit then spoke audit retirement (no spendable local debit).
 */
final class DeskFirstWalletRefundRevokeCoordinator
{
    public function __construct(
        private readonly CentralWalletRefundLedgerReversalService $ledgerReversals,
        private readonly RdServiceInWalletRefundReversalClient $rdServiceInReversalClient,
        private readonly WalletRefundDestinationResolver $destinations,
    ) {}

    /**
     * @return array{
     *     wallet_reversal_reference: string,
     *     wallet_reversal_transaction_id: string,
     *     balance: string,
     * }
     */
    public function reverse(RefundRequest $refund, string $idempotencyKey): array
    {
        $ledger = $this->reverseCentralWalletLedger($refund, $idempotencyKey);
        $this->retireSpokeAudit($refund, $idempotencyKey);

        return [
            'wallet_reversal_reference' => $ledger['wallet_reversal_reference'],
            'wallet_reversal_transaction_id' => $ledger['wallet_reversal_transaction_id'],
            'balance' => $ledger['balance'],
        ];
    }

    /**
     * @return array{
     *     reversal_ledger_entry_id: int,
     *     wallet_reversal_reference: string,
     *     wallet_reversal_transaction_id: string,
     *     balance: string,
     *     idempotent_replay: bool,
     * }
     */
    public function reverseCentralWalletLedger(RefundRequest $refund, string $idempotencyKey): array
    {
        $this->assertDeskFirstRevokeEligible($refund);

        $correlationId = (string) Str::uuid();

        try {
            return $this->ledgerReversals->reverseRefundCredit($refund, $idempotencyKey, $correlationId);
        } catch (InvalidArgumentException $exception) {
            $message = match ($exception->getMessage()) {
                'Insufficient available balance.' => 'Central Wallet balance is insufficient to reverse this desk-first refund credit.',
                'ledger_credit_wallet_mismatch' => 'Central Wallet refund credit does not belong to this customer.',
                default => 'Central Wallet refund reversal could not be posted: '.$exception->getMessage(),
            };

            throw ValidationException::withMessages(['refund' => $message]);
        }
    }

    public function retireSpokeAudit(RefundRequest $refund, string $idempotencyKey): void
    {
        $this->assertDeskFirstRevokeEligible($refund);

        if (! $this->rdServiceInReversalClient->isConfigured()) {
            throw ValidationException::withMessages([
                'refund' => 'rdservice.in wallet refund audit retirement is not configured.',
            ]);
        }

        $refund->loadMissing('order');
        $orderId = trim((string) ($refund->order?->order_id ?? ''));
        $amount = WalletMoney::normalize($refund->refund_amount ?? $refund->amount);
        if ($amount === null) {
            throw ValidationException::withMessages([
                'refund' => 'A positive refund amount is required before wallet reversal can be posted.',
            ]);
        }

        $this->rdServiceInReversalClient->reverseDeskAuthoritativeAudit(
            deskRefundReference: (string) $refund->reference_no,
            orderId: $orderId,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            centralWalletLedgerCreditId: DeskFirstWalletRefundSupport::deskLedgerCreditIdFromExecution($refund),
            customerEmail: $refund->order?->customer_email,
        );
    }

    private function assertDeskFirstRevokeEligible(RefundRequest $refund): void
    {
        if (! DeskFirstWalletRefundSupport::isDeskFirstCompletedExecution($refund)) {
            throw ValidationException::withMessages([
                'refund' => 'This refund is not a desk-first Central Wallet execution.',
            ]);
        }

        $refund->loadMissing('order');
        $orderId = trim((string) ($refund->order?->order_id ?? ''));
        if ($orderId === '') {
            throw ValidationException::withMessages([
                'refund' => 'Refund order id is required before wallet reversal can be posted.',
            ]);
        }

        if (! $this->destinations->isRdServiceIn($orderId)) {
            throw ValidationException::withMessages([
                'refund' => 'Desk-first Central Wallet reversal is only wired for rdservice.in orders in this release.',
            ]);
        }
    }
}
