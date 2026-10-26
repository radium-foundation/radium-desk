<?php

namespace App\Services\Refunds;

use App\CentralWallet\Application\CashfreeCentralCustomerBinder;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Models\RefundRequest;
use App\Support\Money\WalletMoney;
use App\Support\Refunds\DeskFirstWalletRefundSupport;
use Illuminate\Validation\ValidationException;

/**
 * Fail-closed completion guard for desk-first wallet refunds.
 * Verifies authoritative ledger evidence before RefundRequestService marks completed.
 */
final class DeskFirstWalletRefundCompletionGate
{
    public function __construct(
        private readonly CashfreeCentralCustomerBinder $customerBinder,
    ) {}

    /**
     * @param  array{
     *     provider: string,
     *     reference_number: string|null,
     *     transaction_id: string|null,
     *     remarks: string|null,
     *     metadata?: array<string, mixed>,
     * }  $execution
     */
    public function assertMayComplete(RefundRequest $refund, array $execution): void
    {
        if (! DeskFirstWalletRefundSupport::completionGateApplies()) {
            return;
        }

        if ($refund->approved_refund_method !== ApprovedRefundMethod::Wallet) {
            return;
        }

        $metadata = $execution['metadata'] ?? [];
        if (! is_array($metadata) || ($metadata['desk_first'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'refund' => 'Desk-authoritative wallet refund requires a posted Central Wallet credit; legacy spoke-only execution cannot complete.',
            ]);
        }

        $refund->loadMissing('order');

        $reference = trim((string) $refund->reference_no);
        if ($reference === '') {
            throw ValidationException::withMessages([
                'refund' => 'Refund reference is required before wallet completion.',
            ]);
        }

        $ledgerEntryId = $this->resolveLedgerEntryId($execution, $metadata);
        if ($ledgerEntryId === null) {
            throw ValidationException::withMessages([
                'refund' => 'Desk Central Wallet ledger entry could not be resolved for this refund.',
            ]);
        }

        $entry = CentralWalletLedgerEntry::query()
            ->whereKey($ledgerEntryId)
            ->where('business_reference', $reference)
            ->where('entry_type', LedgerEntryType::Credit)
            ->where('status', LedgerEntryStatus::Posted)
            ->first();

        if ($entry === null) {
            throw ValidationException::withMessages([
                'refund' => 'A posted Desk Central Wallet credit for this refund reference was not found.',
            ]);
        }

        $expectedAmount = WalletMoney::normalize($refund->refund_amount ?? $refund->amount);
        $entryAmount = WalletMoney::normalize($entry->amount) ?? (string) $entry->amount;
        if ($expectedAmount === null || bccomp($expectedAmount, $entryAmount, WalletMoney::SCALE) !== 0) {
            throw ValidationException::withMessages([
                'refund' => 'Desk Central Wallet credit amount does not match the approved refund amount.',
            ]);
        }

        if (strtoupper((string) $entry->currency) !== 'INR') {
            throw ValidationException::withMessages([
                'refund' => 'Desk Central Wallet credit currency must be INR.',
            ]);
        }

        $order = $refund->order;
        $customerId = trim((string) ($order?->customer_id ?? ''));
        if ($customerId === '') {
            throw ValidationException::withMessages([
                'refund' => 'Order customer identity is required to verify Central Wallet ownership.',
            ]);
        }

        $resolved = $this->customerBinder->resolveCustomerWallet($customerId);
        if ($resolved === null) {
            throw ValidationException::withMessages([
                'refund' => 'Central Wallet could not be resolved for this customer.',
            ]);
        }

        if ((string) $resolved['central_wallet_id'] !== (string) $entry->central_wallet_id) {
            throw ValidationException::withMessages([
                'refund' => 'Desk Central Wallet credit does not belong to the expected customer wallet.',
            ]);
        }

        $executionReference = trim((string) ($execution['reference_number'] ?? ''));
        $expectedReference = 'CW:'.$ledgerEntryId;
        if ($executionReference !== $expectedReference) {
            throw ValidationException::withMessages([
                'refund' => 'Execution reference does not match the authoritative Desk Central Wallet credit.',
            ]);
        }

        $executionTxn = trim((string) ($execution['transaction_id'] ?? ''));
        if ($executionTxn !== (string) $ledgerEntryId) {
            throw ValidationException::withMessages([
                'refund' => 'Execution transaction id does not match the Desk Central Wallet ledger entry.',
            ]);
        }

        $metadataLedgerId = (int) ($metadata['central_wallet_ledger_entry_id'] ?? 0);
        if ($metadataLedgerId !== $ledgerEntryId) {
            throw ValidationException::withMessages([
                'refund' => 'Execution metadata does not match the Desk Central Wallet ledger entry.',
            ]);
        }

        if (! isset($metadata['spoke_audit']) || ! is_array($metadata['spoke_audit'])) {
            throw ValidationException::withMessages([
                'refund' => 'Spoke audit evidence is required before a desk-first wallet refund can complete.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveLedgerEntryId(array $execution, array $metadata): ?int
    {
        $fromMetadata = (int) ($metadata['central_wallet_ledger_entry_id'] ?? 0);
        if ($fromMetadata > 0) {
            return $fromMetadata;
        }

        $txn = trim((string) ($execution['transaction_id'] ?? ''));
        if ($txn !== '' && ctype_digit($txn)) {
            return (int) $txn;
        }

        $reference = trim((string) ($execution['reference_number'] ?? ''));
        if (preg_match('/^CW:([1-9]\d*)$/', $reference, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
