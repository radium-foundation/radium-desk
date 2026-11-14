<?php

namespace App\Services\RadiumBox\Contracts;

use App\Data\RadiumBox\RadiumBoxPaymentConfirmationResult;
use App\Models\Order;

interface RadiumBoxPaymentDownstreamGateway
{
    public function contractName(): string;

    public function notifyPaid(Order $order, bool $dryRun = false): RadiumBoxPaymentConfirmationResult;
}