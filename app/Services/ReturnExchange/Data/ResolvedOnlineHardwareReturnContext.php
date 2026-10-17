<?php

namespace App\Services\ReturnExchange\Data;

use App\Models\HardwareFulfilment;
use App\Models\Order;

final class ResolvedOnlineHardwareReturnContext
{
    public function __construct(
        public readonly Order $order,
        public readonly ?HardwareFulfilment $fulfilment,
        public readonly ?int $branchId,
    ) {}
}
