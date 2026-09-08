<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;

final class HardwareAwaitingFulfilmentRow
{
    public function __construct(
        public readonly Order $order,
        public readonly HardwareAwaitingFulfilmentReason $reason,
        public readonly bool $paid,
        public readonly bool $hasSupportSerial,
        public readonly bool $transactionLocked,
        public readonly string $createdAtIst,
    ) {}

    public static function fromOrder(Order $order): self
    {
        $created = $order->created_at?->copy()->timezone(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        return new self(
            order: $order,
            reason: HardwareAwaitingFulfilmentClassifier::reason($order),
            paid: $order->isCashfreeVerified(),
            hasSupportSerial: $order->isSerialLocked(),
            transactionLocked: $order->isTransactionLocked(),
            createdAtIst: $created?->format('Y-m-d H:i') ?? '—',
        );
    }

    public function orderUrl(): string
    {
        return route('orders.show', $this->order);
    }

    public function customer360Url(): string
    {
        return route('dashboard.orders.customer-360', $this->order);
    }
}
