<?php

namespace App\Services\ReturnExchange;

use App\Models\Order;
use App\Services\WalletRefundOpmConversion\Contracts\WalletRefundOpmSpokeSubject;

final class ReturnExchangeAllocationWalletOpmSpokeSubject implements WalletRefundOpmSpokeSubject
{
    public function __construct(
        private readonly string $deskRefundReference,
        private readonly Order $order,
    ) {}

    public function deskRefundReference(): string
    {
        return $this->deskRefundReference;
    }

    public function order(): ?Order
    {
        return $this->order;
    }
}
