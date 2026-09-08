<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareFulfilmentOperationalStage;

final class HardwareFulfilmentOperationalRow
{
    public function __construct(
        public readonly string $sourceId,
        public readonly string $orderDateIst,
        public readonly string $customer,
        public readonly string $product,
        public readonly string $sku,
        public readonly string $quantity,
        public readonly string $payment,
        public readonly string $fulfilmentStatus,
        public readonly string $serialStatus,
        public readonly string $invoiceStatus,
        public readonly string $shipmentStatus,
        public readonly string $awbStatus,
        public readonly HardwareFulfilmentOperationalStage $stage,
        public readonly string $nextAction,
        public readonly ?string $nextUrl,
        public readonly ?string $blocker,
        public readonly ?int $fulfilmentId,
        public readonly ?int $supportOrderId,
        public readonly bool $hasFulfilment,
    ) {}

    public function openOrderUrl(): ?string
    {
        if ($this->supportOrderId === null) {
            return null;
        }

        return route('orders.show', $this->supportOrderId);
    }

    public function customer360Url(): ?string
    {
        if ($this->supportOrderId === null) {
            return null;
        }

        return route('dashboard.orders.customer-360', $this->supportOrderId);
    }

    public function fulfilmentUrl(): ?string
    {
        if ($this->fulfilmentId === null) {
            return null;
        }

        return route('inventory.hardware-fulfilments.show', $this->fulfilmentId);
    }
}
