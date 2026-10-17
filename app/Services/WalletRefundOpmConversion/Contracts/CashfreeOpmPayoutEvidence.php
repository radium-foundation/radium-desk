<?php

namespace App\Services\WalletRefundOpmConversion\Contracts;

interface CashfreeOpmPayoutEvidence
{
    public function getCashfreeOrderId(): ?string;

    public function getCashfreeRefundId(): ?string;

    public function getCashfreeRefundAmount(): ?string;
}
