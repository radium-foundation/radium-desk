<?php

namespace App\Services\ReturnExchange\Data;

use App\Enums\ReturnExchangeCaseSourceKind;

final class ResolvedReturnExchangeCaseSource
{
    public function __construct(
        public readonly ReturnExchangeCaseSourceKind $kind,
        public readonly ?int $branchId,
        public readonly ?int $inventorySaleId,
        public readonly ?int $orderId,
        public readonly ?int $hardwareFulfilmentId,
    ) {}
}
