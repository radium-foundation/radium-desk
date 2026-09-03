<?php

namespace App\Services\ChannelIngest\Data;

use App\Enums\ChannelIngestOutcome;
use App\Models\CommerceOrder;

final class ChannelIngestResult
{
    public function __construct(
        public readonly ChannelIngestOutcome $outcome,
        public readonly int $httpStatus,
        public readonly ?CommerceOrder $order = null,
        public readonly ?string $error = null,
        public readonly bool $duplicate = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->outcome === ChannelIngestOutcome::Conflict) {
            return [
                'status' => ChannelIngestOutcome::Conflict->value,
                'accepted' => false,
                'duplicate' => false,
                'error' => $this->error,
                'order_no' => $this->order?->order_no,
                'invoice' => null,
            ];
        }

        if ($this->order === null) {
            return [
                'status' => $this->outcome->value,
                'accepted' => false,
                'duplicate' => $this->duplicate,
                'error' => $this->error,
                'invoice' => null,
            ];
        }

        return [
            'status' => $this->order->status->value,
            'accepted' => true,
            'duplicate' => $this->duplicate,
            'order_no' => $this->order->order_no,
            'channel' => $this->order->channel->value,
            'source_type' => $this->order->source_type,
            'source_id' => $this->order->source_id,
            'idempotency_key' => $this->order->idempotency_key,
            'invoice_eligible' => $this->order->invoice_eligible,
            'status_reason' => $this->order->status_reason,
            'invoice' => $this->order->statutory_invoice_id === null ? null : [
                'id' => $this->order->statutory_invoice_id,
            ],
        ];
    }
}
