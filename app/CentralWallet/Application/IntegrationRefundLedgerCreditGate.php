<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\Models\RefundRequest;
use InvalidArgumentException;

/**
 * Blocks external integration API credits that would duplicate unpaired spoke wallet refunds.
 */
final class IntegrationRefundLedgerCreditGate
{
    public function __construct(
        private readonly WalletRefundAuthoritativeCreditGuard $authoritativeCreditGuard,
    ) {}

    public function rejectionErrorCode(
        string $callerId,
        LedgerEntryType $entryType,
        ?string $businessReference,
    ): ?string {
        if ($callerId === ExternalDirectLedgerDebitGate::INTERNAL_CALLER) {
            return null;
        }

        if ($entryType !== LedgerEntryType::Credit) {
            return null;
        }

        $reference = trim((string) $businessReference);
        if ($reference === '') {
            return null;
        }

        $refund = RefundRequest::query()
            ->where('reference_no', $reference)
            ->first();

        if ($refund === null) {
            return null;
        }

        if ($this->authoritativeCreditGuard->hasPostedDeskCreditForReference($reference)) {
            return 'refund_ledger_credit_already_posted';
        }

        try {
            $this->authoritativeCreditGuard->assertMayPostStandaloneDeskCredit($refund);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return null;
    }
}
