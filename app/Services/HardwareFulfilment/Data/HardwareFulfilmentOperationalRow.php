<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareDashboardQueue;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareOperationsSection;

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
        public readonly string $source = 'RDE',
        public readonly HardwareOperationsSection $section = HardwareOperationsSection::NeedsFulfilment,
        public readonly ?string $nextAnchor = null,
        public readonly bool $mutatingAction = false,
        public readonly bool $packagePhotoRecorded = false,
        public readonly ?string $statusLabel = null,
        /** @var list<array{label: string, qty: ?int}> */
        public readonly array $productLines = [],
        public readonly bool $productMissing = false,
    ) {}

    public function operatorStatus(): string
    {
        return $this->statusLabel ?? $this->stage->label();
    }

    public function dashboardQueue(): HardwareDashboardQueue
    {
        return HardwareDashboardQueue::fromStage($this->stage, $this->packagePhotoRecorded);
    }

    public function serialDisplay(): string
    {
        $raw = trim($this->serialStatus);
        if ($raw === '' || in_array($raw, ['Not allocated', 'None', 'On support order', '—'], true)) {
            return '—';
        }

        $first = trim((string) explode(',', $raw)[0]);

        return $first !== '' ? $first : '—';
    }

    public function productDisplay(): string
    {
        if ($this->productMissing) {
            return 'Product data missing';
        }

        $product = trim($this->product);

        return $product !== '' ? $product : 'Product data missing';
    }

    /**
     * @return list<array{label: string, qty: ?int}>
     */
    public function productDetails(): array
    {
        return $this->productLines;
    }

    public function productHasMore(): bool
    {
        return count($this->productLines) > 1;
    }

    public function productExceptionAction(): ?string
    {
        if (! $this->productMissing) {
            return null;
        }

        return $this->hasFulfilment ? 'Fix order' : 'Review';
    }

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

    public function primaryUrl(): ?string
    {
        if ($this->nextUrl === null) {
            return $this->customer360Url();
        }

        if ($this->nextAnchor !== null && $this->nextAnchor !== '') {
            return $this->nextUrl.'#'.$this->nextAnchor;
        }

        return $this->nextUrl;
    }
}
