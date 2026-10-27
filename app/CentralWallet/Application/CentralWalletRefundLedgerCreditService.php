<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Services\Refunds\WalletRefundDestinationResolver;
use App\Support\Money\WalletMoney;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Authoritative Desk Central Wallet credit for wallet-approved refunds.
 * Idempotent on refund reference and refund_requests source reference.
 */
final class CentralWalletRefundLedgerCreditService
{
    public function __construct(
        private readonly CashfreeCentralCustomerBinder $customerBinder,
        private readonly LedgerService $ledger,
        private readonly WalletRefundDestinationResolver $destinations,
        private readonly WalletRefundAuthoritativeCreditGuard $authoritativeCreditGuard,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('central_wallet.wallet_refund.desk_first_enabled', false);
    }

    /**
     * @return array{
     *     ledger_entry_id: int,
     *     central_wallet_id: string,
     *     wallet_reference: string,
     *     available_balance: string,
     *     idempotent_replay: bool,
     * }
     */
    public function creditForRefund(RefundRequest $refund, string $correlationId): array
    {
        if (! $this->isEnabled()) {
            throw new InvalidArgumentException('desk_first_wallet_refund_disabled');
        }

        $refund->loadMissing('order');
        $order = $refund->order;
        if (! $order instanceof Order) {
            throw new InvalidArgumentException('refund_order_missing');
        }

        $orderId = trim((string) ($order->order_id ?? ''));
        if ($orderId === '') {
            throw new InvalidArgumentException('refund_order_id_missing');
        }

        $amount = WalletMoney::normalize($refund->refund_amount ?? $refund->amount);
        if ($amount === null || ! WalletMoney::isPositive($amount)) {
            throw new InvalidArgumentException('refund_amount_invalid');
        }

        $reference = trim((string) $refund->reference_no);
        if ($reference === '') {
            throw new InvalidArgumentException('refund_reference_missing');
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
        $sourceSystem = $this->sourceSystemForOrder($orderId);

        $this->authoritativeCreditGuard->assertMayPostStandaloneDeskCredit($refund);

        $existing = $this->findExistingCredit($refund, $reference);
        if ($existing !== null) {
            $existingAmount = WalletMoney::normalize($existing->amount) ?? (string) $existing->amount;
            if (bccomp($existingAmount, $amount, WalletMoney::SCALE) !== 0) {
                throw new InvalidArgumentException('refund_ledger_credit_amount_mismatch');
            }

            return [
                'ledger_entry_id' => (int) $existing->id,
                'central_wallet_id' => $centralWalletId,
                'wallet_reference' => $this->walletReference((int) $existing->id),
                'available_balance' => $this->ledger->availableBalance($centralWalletId),
                'idempotent_replay' => true,
            ];
        }

        $entry = $this->ledger->appendEntry(
            centralWalletId: $centralWalletId,
            entryType: LedgerEntryType::Credit,
            amount: $amount,
            sourceSystem: $sourceSystem,
            correlationId: $correlationId,
            sourceReference: RefundMigrationIdempotencyKey::ledgerSourceReference((int) $refund->id),
            businessReference: $reference,
            metadata: [
                'refund_request_id' => (int) $refund->id,
                'desk_order_id' => $orderId,
                'wallet_refund_flow' => 'desk_first',
            ],
        );

        return [
            'ledger_entry_id' => (int) $entry->id,
            'central_wallet_id' => $centralWalletId,
            'wallet_reference' => $this->walletReference((int) $entry->id),
            'available_balance' => $this->ledger->availableBalance($centralWalletId),
            'idempotent_replay' => false,
        ];
    }

    private function sourceSystemForOrder(string $orderId): string
    {
        if ($this->destinations->isRdServiceIn($orderId)) {
            return WalletRefundDestinationResolver::RDSERVICE_IN;
        }

        if ($this->destinations->isRdServiceNet($orderId)) {
            return WalletRefundDestinationResolver::RDSERVICE_NET;
        }

        if ($this->destinations->isRadiumBox($orderId)) {
            return 'radiumbox.com';
        }

        throw new InvalidArgumentException('unsupported_wallet_refund_order_prefix');
    }

    private function findExistingCredit(RefundRequest $refund, string $reference): ?CentralWalletLedgerEntry
    {
        $byBusiness = CentralWalletLedgerEntry::query()
            ->where('business_reference', $reference)
            ->where('entry_type', LedgerEntryType::Credit)
            ->first();

        if ($byBusiness !== null) {
            return $byBusiness;
        }

        $sourceRef = RefundMigrationIdempotencyKey::ledgerSourceReference((int) $refund->id);

        return CentralWalletLedgerEntry::query()
            ->where('source_reference', $sourceRef)
            ->where('entry_type', LedgerEntryType::Credit)
            ->first();
    }

    private function walletReference(int $ledgerEntryId): string
    {
        return 'CW:'.$ledgerEntryId;
    }
}
