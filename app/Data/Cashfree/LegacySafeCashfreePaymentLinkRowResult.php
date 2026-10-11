<?php

namespace App\Data\Cashfree;

use App\Enums\LegacySafeCashfreePaymentLinkResult;

final readonly class LegacySafeCashfreePaymentLinkRowResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public int $deskOrderId,
        public string $deskOrderNumber,
        public LegacySafeCashfreePaymentLinkResult $result,
        public string $reason,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'desk_order_id' => $this->deskOrderId,
            'desk_order_number' => $this->deskOrderNumber,
            'result' => $this->result->value,
            'reason' => $this->reason,
            'details' => $this->details,
        ];
    }
}
