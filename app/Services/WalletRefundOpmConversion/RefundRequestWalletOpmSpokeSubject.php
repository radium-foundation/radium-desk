<?php

namespace App\Services\WalletRefundOpmConversion;

use App\Models\Order;
use App\Models\RefundRequest;
use App\Services\WalletRefundOpmConversion\Contracts\WalletRefundOpmSpokeSubject;

final class RefundRequestWalletOpmSpokeSubject implements WalletRefundOpmSpokeSubject
{
    public function __construct(
        private readonly RefundRequest $refund,
    ) {}

    public function deskRefundReference(): string
    {
        return (string) $this->refund->reference_no;
    }

    public function order(): ?Order
    {
        $this->refund->loadMissing('order');

        return $this->refund->order;
    }
}
