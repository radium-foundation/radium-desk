<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Support\Money\WalletMoney;
use App\Support\Refunds\DeskFirstWalletRefundSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Compensating Desk Central Wallet debit for desk-first wallet refund revoke.
 */
final class CentralWalletRefundLedgerReversalService
{
    public function __construct(
        private readonly CashfreeCentralCustomerBinder $customerBinder,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @return array{
     *     reversal_ledger_entry_id: int,
     *     wallet_reversal_reference: string,
     *     wallet_reversal_transaction_id: string,
     *     balance: string,
     *     idempotent_replay: bool,
     * }
     */
    public function reverseRefundCredit(
        RefundRequest $refund,
        string $idempotencyKey,
        string $correlationId,
    ): array {
        if (! DeskFirstWalletRefundSupport::hasPersistedDeskFirstCentralWalletExecution($refund)) {
            throw new InvalidArgumentException('desk_first_execution_reference_missing');
        }

        $creditId = DeskFirstWalletRefundSupport::deskLedgerCreditIdFromExecution($refund);
        if ($creditId === null) {
            throw new InvalidArgumentException('desk_first_ledger_credit_unresolved');
        }

        $original = CentralWalletLedgerEntry::query()->find($creditId);
        if ($original === null || $original->entry_type !== LedgerEntryType::Credit) {
            throw new InvalidArgumentException('original_refund_ledger_credit_not_found');
        }

        $refund->loadMissing('order');
        $order = $refund->order;
        if (! $order instanceof Order) {
            throw new InvalidArgumentException('refund_order_missing');
        }

        $customerId = trim((string) ($order->customer_id ?? ''));
        if ($customerId === '' || ! Str::isUuid($customerId)) {
            throw new InvalidArgumentException('order_customer_unbound');
        }

        $resolved = $this->customerBinder->resolveCustomerWallet($customerId);
        if ($resolved === null) {
            throw new InvalidArgumentException('central_wallet_unresolved_for_customer');
        }

        $centralWalletId = (string) $resolved['central_wallet_id'];
        if ($original->central_wallet_id !== $centralWalletId) {
            throw new InvalidArgumentException('ledger_credit_wallet_mismatch');
        }

        $amount = WalletMoney::normalize($refund->refund_amount ?? $refund->amount);
        if ($amount === null || ! WalletMoney::isPositive($amount)) {
            throw new InvalidArgumentException('refund_amount_invalid');
        }

        $creditAmount = WalletMoney::normalize($original->amount) ?? (string) $original->amount;
        if (bccomp($creditAmount, $amount, WalletMoney::SCALE) !== 0) {
            throw new InvalidArgumentException('refund_ledger_credit_amount_mismatch');
        }

        $sourceReference = DeskFirstWalletRefundSupport::revokeLedgerSourceReference((int) $refund->id);

        $existing = CentralWalletLedgerEntry::query()
            ->where('source_reference', $sourceReference)
            ->where('entry_type', LedgerEntryType::Debit)
            ->first();

        if ($existing !== null) {
            return $this->envelope($existing, $centralWalletId, true);
        }

        $debit = DB::transaction(function () use (
            $centralWalletId,
            $amount,
            $correlationId,
            $sourceReference,
            $refund,
            $creditId,
            $original,
        ): CentralWalletLedgerEntry {
            $duplicate = CentralWalletLedgerEntry::query()
                ->where('source_reference', $sourceReference)
                ->where('entry_type', LedgerEntryType::Debit)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                return $duplicate;
            }

            return $this->ledger->appendEntry(
                centralWalletId: $centralWalletId,
                entryType: LedgerEntryType::Debit,
                amount: $amount,
                sourceSystem: 'radium-desk',
                correlationId: $correlationId,
                sourceReference: $sourceReference,
                businessReference: (string) $refund->reference_no,
                metadata: [
                    'refund_request_id' => (int) $refund->id,
                    'wallet_refund_flow' => 'desk_first_revoke',
                    'original_refund_ledger_credit_id' => $creditId,
                    'original_business_reference' => $original->business_reference,
                ],
            );
        });

        return $this->envelope($debit, $centralWalletId, false);
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
    private function envelope(CentralWalletLedgerEntry $debit, string $centralWalletId, bool $replay): array
    {
        $id = (int) $debit->id;

        return [
            'reversal_ledger_entry_id' => $id,
            'wallet_reversal_reference' => 'CW-R:'.$id,
            'wallet_reversal_transaction_id' => (string) $id,
            'balance' => $this->ledger->availableBalance($centralWalletId),
            'idempotent_replay' => $replay,
        ];
    }
}
