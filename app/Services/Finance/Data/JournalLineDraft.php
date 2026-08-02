<?php

namespace App\Services\Finance\Data;

readonly class JournalLineDraft
{
    public function __construct(
        public int $accountId,
        public float $debit = 0.0,
        public float $credit = 0.0,
        public ?string $description = null,
    ) {}

    public static function debit(int $accountId, float $amount, ?string $description = null): self
    {
        return new self($accountId, debit: round($amount, 2), credit: 0.0, description: $description);
    }

    public static function credit(int $accountId, float $amount, ?string $description = null): self
    {
        return new self($accountId, debit: 0.0, credit: round($amount, 2), description: $description);
    }
}
