<?php

namespace App\Services\Shipping\Data;

use App\Enums\ShiprocketWalletBalanceStatus;
use Illuminate\Support\Carbon;

final class ShiprocketWalletBalancePresentation
{
    public function __construct(
        public readonly ShiprocketWalletBalanceStatus $status,
        public readonly ?string $balanceAmount = null,
        public readonly ?Carbon $checkedAt = null,
        public readonly bool $isStale = false,
    ) {}

    public function showsBalanceAmount(): bool
    {
        return $this->status->showsBalanceAmount() && $this->balanceAmount !== null;
    }

    public function checkedMinutesAgo(): ?int
    {
        if ($this->checkedAt === null) {
            return null;
        }

        return max(0, (int) $this->checkedAt->diffInMinutes(now()));
    }
}
