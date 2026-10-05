<?php

namespace App\Services\Refunds;

use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Support\Money\WalletMoney;
use Illuminate\Support\Collection;

final class WalletRefundExistingCreditDetector
{
    /** @var list<string> */
    private const CENTRAL_WALLET_SOURCE_SYSTEMS = [
        WalletRefundDestinationResolver::RDSERVICE_IN,
        WalletRefundDestinationResolver::RDSERVICE_NET,
    ];

    public function __construct(
        private readonly WalletRefundDestinationResolver $destinations,
    ) {}

    public function supports(RefundRequest $refund): bool
    {
        $refund->loadMissing('order');
        $orderId = trim((string) ($refund->order?->order_id ?? ''));

        if ($orderId === '') {
            return false;
        }

        if ($this->destinations->isRdServiceIn($orderId)) {
            return true;
        }

        return $this->destinations->isRdServiceNet($orderId)
            && $this->destinations->isRdServiceNetWalletRefundConfigured();
    }

    public function detect(RefundRequest $refund): WalletRefundExistingCreditDetection
    {
        if (! $this->supports($refund)) {
            return WalletRefundExistingCreditDetection::unsupported();
        }

        $refund->loadMissing('order');

        $reference = trim((string) $refund->reference_no);
        $orderId = trim((string) ($refund->order?->order_id ?? ''));

        if ($reference === '' || $orderId === '') {
            return WalletRefundExistingCreditDetection::notFound();
        }

        $expectedAmount = WalletMoney::normalize($refund->refund_amount ?? $refund->amount);
        if ($expectedAmount === null || ! WalletMoney::isPositive($expectedAmount)) {
            return WalletRefundExistingCreditDetection::notFound();
        }

        $expectedCurrency = strtoupper((string) config('central_wallet.currency', 'INR'));
        $expectedSourceReference = 'desk_refund:'.$orderId;

        /** @var Collection<int, CentralWalletLedgerEntry> $candidates */
        $candidates = CentralWalletLedgerEntry::query()
            ->where('business_reference', $reference)
            ->where('entry_type', LedgerEntryType::Credit)
            ->where('status', LedgerEntryStatus::Posted)
            ->whereIn('source_system', self::CENTRAL_WALLET_SOURCE_SYSTEMS)
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return WalletRefundExistingCreditDetection::notFound();
        }

        if ($candidates->count() > 1) {
            return WalletRefundExistingCreditDetection::ambiguous($candidates->count());
        }

        $entry = $candidates->first();
        if ($entry === null) {
            return WalletRefundExistingCreditDetection::notFound();
        }

        $actualAmount = WalletMoney::normalize($entry->amount);
        if ($actualAmount === null || bccomp($actualAmount, $expectedAmount, WalletMoney::SCALE) !== 0) {
            return WalletRefundExistingCreditDetection::amountMismatch(
                $expectedAmount,
                $actualAmount ?? (string) $entry->amount,
            );
        }

        $actualCurrency = strtoupper(trim((string) $entry->currency));
        if ($actualCurrency !== $expectedCurrency) {
            return WalletRefundExistingCreditDetection::currencyMismatch($expectedCurrency, $actualCurrency);
        }

        $sourceReference = trim((string) ($entry->source_reference ?? ''));
        if ($sourceReference !== $expectedSourceReference) {
            return WalletRefundExistingCreditDetection::sourceMismatch();
        }

        if ($this->isReversed($entry)) {
            return WalletRefundExistingCreditDetection::reversed($entry);
        }

        $consumingRefund = $this->findConsumingRefund($entry, $refund);
        if ($consumingRefund !== null) {
            return WalletRefundExistingCreditDetection::alreadyConsumed(
                $entry,
                (string) $consumingRefund->reference_no,
            );
        }

        return WalletRefundExistingCreditDetection::matched($entry);
    }

    private function isReversed(CentralWalletLedgerEntry $entry): bool
    {
        return CentralWalletLedgerEntry::query()
            ->where('entry_type', LedgerEntryType::Reversal)
            ->where('status', LedgerEntryStatus::Posted)
            ->where('original_ledger_entry_id', $entry->id)
            ->exists();
    }

    private function findConsumingRefund(CentralWalletLedgerEntry $entry, RefundRequest $refund): ?RefundRequest
    {
        $ledgerId = (string) $entry->id;
        $walletReference = 'CW:'.$entry->id;

        return RefundRequest::query()
            ->where('id', '!=', $refund->id)
            ->whereIn('status', [RefundStatus::Completed, RefundStatus::Closed])
            ->where(function ($query) use ($ledgerId, $walletReference): void {
                $query->where('execution_transaction_id', $ledgerId)
                    ->orWhere('execution_reference_no', $walletReference)
                    ->orWhere('execution_reference_no', $ledgerId);
            })
            ->first();
    }
}
