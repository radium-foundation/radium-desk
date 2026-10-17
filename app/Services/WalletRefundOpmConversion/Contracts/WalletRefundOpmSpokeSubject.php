<?php

namespace App\Services\WalletRefundOpmConversion\Contracts;

use App\Models\Order;

interface WalletRefundOpmSpokeSubject
{
    public function deskRefundReference(): string;

    public function order(): ?Order;
}
