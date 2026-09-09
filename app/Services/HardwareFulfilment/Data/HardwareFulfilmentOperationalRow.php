<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareDashboardQueue;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareOperationsSection;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Support\HardwareFulfilment\HardwareAllocatedSerialDisplay;
use Illuminate\Support\Carbon;

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
        /** @var list<string> */
        public readonly array $allocatedSerialNumbers = [],
        public readonly ?int $expectedSerialQuantity = null,
        public readonly ?string $productStatusLabel = null,
    ) {}

    public function operatorStatus(): string
    {
        return $this->statusLabel ?? $this->stage->label();
    }

    /**
     * Compact operator date/time from the existing IST sort field. No extra query.
     * Example: 09 Sep · 8:18 PM
     */
    public function orderDateDisplay(): string
    {
        return $this->formatOrderDate('d M · g:i A');
    }

    /**
     * Narrow-screen variant without the middle dot. Example: 09 Sep 8:18 PM
     */
    public function orderDateDisplayCompact(): string
    {
        return $this->formatOrderDate('d M g:i A');
    }

    private function formatOrderDate(string $format): string
    {
        $raw = trim($this->orderDateIst);
        if ($raw === '' || $raw === '—') {
            return '—';
        }

        try {
            $parsed = Carbon::createFromFormat(
                'Y-m-d H:i',
                $raw,
                HardwareFulfilmentEligibility::CUTOFF_TIMEZONE,
            );
        } catch (\Throwable) {
            return $raw;
        }

        if (! $parsed instanceof Carbon) {
            return $raw;
        }

        return $parsed->format($format);
    }

    public function dashboardQueue(): HardwareDashboardQueue
    {
        return HardwareDashboardQueue::fromStage($this->stage, $this->packagePhotoRecorded);
    }

    /**
     * @return list<string>
     */
    public function allocatedSerials(): array
    {
        $serials = HardwareAllocatedSerialDisplay::normalize($this->allocatedSerialNumbers);
        if ($serials !== []) {
            return $serials;
        }

        $raw = trim($this->serialStatus);
        if ($raw === '' || in_array($raw, ['Not allocated', 'None', 'On support order', '—'], true)) {
            return [];
        }

        return HardwareAllocatedSerialDisplay::normalize(array_map('trim', explode(',', $raw)));
    }

    public function serialDisplay(): string
    {
        return HardwareAllocatedSerialDisplay::compact($this->allocatedSerials(), $this->expectedSerialQuantity);
    }

    public function serialsComplete(): bool
    {
        return HardwareAllocatedSerialDisplay::isComplete(
            $this->allocatedSerials(),
            $this->expectedSerialQuantity,
        );
    }

    public function productDisplay(): string
    {
        if ($this->productMissing) {
            $label = trim((string) $this->productStatusLabel);

            return $label !== '' ? $label : 'Product data missing';
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

        if ($this->nextAction !== '' && $this->nextAction !== 'Review') {
            return $this->nextAction;
        }

        return $this->hasFulfilment ? 'Fix order' : 'View';
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
        if ($this->mutatingAction && $this->nextUrl !== null) {
            return $this->nextAnchor !== null && $this->nextAnchor !== ''
                ? $this->nextUrl.'#'.$this->nextAnchor
                : $this->nextUrl;
        }

        if ($this->nextUrl === null) {
            return $this->customer360Url();
        }

        if ($this->nextAnchor !== null && $this->nextAnchor !== '') {
            return $this->nextUrl.'#'.$this->nextAnchor;
        }

        return $this->nextUrl;
    }

    public function awaitingActionDialogUrl(): ?string
    {
        if ($this->nextAction !== 'Open Fulfilment' || $this->supportOrderId === null) {
            return null;
        }

        return route('inventory.hardware-fulfilments.awaiting.action-dialog', $this->supportOrderId);
    }
}
