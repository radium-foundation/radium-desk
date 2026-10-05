<?php

namespace App\Services\Refunds;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;

final class WalletRefundExistingCreditDetection
{
    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_AMOUNT_MISMATCH = 'amount_mismatch';

    public const STATUS_CURRENCY_MISMATCH = 'currency_mismatch';

    public const STATUS_SOURCE_MISMATCH = 'source_mismatch';

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_ALREADY_CONSUMED = 'already_consumed';

    public const STATUS_UNSUPPORTED = 'unsupported';

    private function __construct(
        private readonly string $status,
        private readonly ?CentralWalletLedgerEntry $ledgerEntry = null,
        private readonly ?string $adminMessage = null,
    ) {}

    public static function notFound(): self
    {
        return new self(self::STATUS_NOT_FOUND);
    }

    public static function unsupported(): self
    {
        return new self(self::STATUS_UNSUPPORTED);
    }

    public static function matched(CentralWalletLedgerEntry $entry): self
    {
        return new self(self::STATUS_MATCHED, $entry);
    }

    public static function ambiguous(int $matchCount): self
    {
        return new self(
            self::STATUS_AMBIGUOUS,
            adminMessage: 'Multiple Central Wallet credits match this refund reference. Manual review is required before completion.',
        );
    }

    public static function amountMismatch(string $expected, string $actual): self
    {
        return new self(
            self::STATUS_AMOUNT_MISMATCH,
            adminMessage: 'An existing Central Wallet credit was found but the amount does not match this refund (expected ₹'
                .$expected.', found ₹'.$actual.').',
        );
    }

    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self(
            self::STATUS_CURRENCY_MISMATCH,
            adminMessage: 'An existing Central Wallet credit was found but the currency does not match (expected '
                .$expected.', found '.$actual.').',
        );
    }

    public static function sourceMismatch(): self
    {
        return new self(
            self::STATUS_SOURCE_MISMATCH,
            adminMessage: 'An existing Central Wallet credit was found but its order source reference does not match this refund.',
        );
    }

    public static function reversed(CentralWalletLedgerEntry $entry): self
    {
        return new self(
            self::STATUS_REVERSED,
            $entry,
            adminMessage: 'The matching Central Wallet credit (CW:'.$entry->id.') has been reversed and cannot be used for completion.',
        );
    }

    public static function alreadyConsumed(CentralWalletLedgerEntry $entry, string $otherRefundReference): self
    {
        return new self(
            self::STATUS_ALREADY_CONSUMED,
            $entry,
            adminMessage: 'The matching Central Wallet credit (CW:'.$entry->id.') is already linked to refund '
                .$otherRefundReference.'.',
        );
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isMatched(): bool
    {
        return $this->status === self::STATUS_MATCHED;
    }

    public function isNotFound(): bool
    {
        return $this->status === self::STATUS_NOT_FOUND;
    }

    public function isUnsupported(): bool
    {
        return $this->status === self::STATUS_UNSUPPORTED;
    }

    public function isBlocking(): bool
    {
        return ! in_array($this->status, [self::STATUS_NOT_FOUND, self::STATUS_MATCHED, self::STATUS_UNSUPPORTED], true);
    }

    public function ledgerEntry(): ?CentralWalletLedgerEntry
    {
        return $this->ledgerEntry;
    }

    public function adminMessage(): ?string
    {
        return $this->adminMessage;
    }

    public function walletReference(): ?string
    {
        if ($this->ledgerEntry === null) {
            return null;
        }

        return 'CW:'.$this->ledgerEntry->id;
    }
}
