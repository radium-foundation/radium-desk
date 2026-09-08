<?php

namespace App\Services\HardwareFulfilment;

use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\InventoryProduct;
use App\Models\InventoryProductPackaging;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentParcelSnapshotService
{
    /**
     * @return array<string, mixed>
     */
    public function attachFromCatalog(HardwareFulfilment $fulfilment, ?User $actor = null): array
    {
        $existing = $this->validSnapshot($fulfilment);
        if ($existing !== null) {
            return $existing;
        }

        $reason = $this->ineligibleReason($fulfilment);
        if ($reason !== null) {
            throw ValidationException::withMessages([
                'parcel' => $reason,
            ]);
        }

        $snapshot = $this->buildSnapshot($fulfilment, $actor);
        $fulfilment->forceFill([
            'parcel_snapshot' => $snapshot,
        ])->save();

        return $snapshot;
    }

    /**
     * Create-lock helper. Never writes when rules fail. Never replaces a snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function attachIfEligible(HardwareFulfilment $fulfilment, ?User $actor = null): ?array
    {
        $existing = $this->validSnapshot($fulfilment);
        if ($existing !== null) {
            return $existing;
        }

        if ($this->ineligibleReason($fulfilment) !== null) {
            return null;
        }

        return $this->attachFromCatalog($fulfilment, $actor);
    }

    public function canAttach(HardwareFulfilment $fulfilment): bool
    {
        return $this->validSnapshot($fulfilment) === null
            && $this->ineligibleReason($fulfilment) === null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validSnapshot(HardwareFulfilment $fulfilment): ?array
    {
        $snapshot = $fulfilment->parcel_snapshot;
        if (! is_array($snapshot)) {
            return null;
        }

        return $this->measuresFrom($snapshot);
    }

    /**
     * Display-only catalog pack from allocated serial identity.
     *
     * @return array{label: string, verified: bool}|null
     */
    public function catalogPackaging(HardwareFulfilment $fulfilment): ?array
    {
        $product = $this->singleAllocatedProduct($fulfilment);
        if ($product === null) {
            return null;
        }

        $pack = $product->packaging;
        if ($pack === null) {
            return [
                'label' => 'Not verified',
                'verified' => false,
            ];
        }

        return [
            'label' => sprintf(
                '%s kg · %s×%s×%s cm',
                $this->formatNumber($pack->gross_weight),
                $this->formatNumber($pack->length),
                $this->formatNumber($pack->breadth),
                $this->formatNumber($pack->height),
            ),
            'verified' => $this->packagingIsUsable($pack),
        ];
    }

    public function ineligibleReason(HardwareFulfilment $fulfilment): ?string
    {
        $fulfilment->loadMissing([
            'commerceOrder.items',
            'serials.inventorySerial.product.packaging',
            'shipment',
        ]);

        if (HardwareFulfilmentEligibility::isFrozenSourceId((string) $fulfilment->source_id)) {
            return 'Frozen pending hardware orders cannot receive a parcel snapshot.';
        }

        if ($this->boundShipment($fulfilment) !== null) {
            return 'A bound shipment already exists. The parcel snapshot cannot be changed.';
        }

        $order = $fulfilment->commerceOrder;
        if ($order === null) {
            return 'Hardware fulfilment is missing its commerce order.';
        }

        if ($this->completeIngestParcel($order->parcel) !== null) {
            return 'An ingest parcel is already persisted. Catalog packaging is not copied.';
        }

        $productIds = $this->allocatedProductIds($fulfilment);
        if ($productIds === []) {
            return 'Serial allocation required';
        }

        if (count($productIds) > 1) {
            return 'Multi-SKU hardware cannot use catalog packaging. A measured order parcel is required.';
        }

        $physicalQty = $this->physicalQty($order);
        if ($physicalQty !== 1) {
            return 'Catalog packaging can only be snapshotted for quantity 1.';
        }

        $product = $this->singleAllocatedProduct($fulfilment);
        $pack = $product?->packaging;
        if ($pack === null || ! $this->packagingIsUsable($pack)) {
            return 'Verified catalog packaging is missing.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $parcel
     * @return array{weight: float, length: float, breadth: float, height: float}|null
     */
    public function completeIngestParcel(?array $parcel): ?array
    {
        return $this->measuresFrom($parcel);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{weight: float, length: float, breadth: float, height: float}|null
     */
    private function measuresFrom(?array $snapshot): ?array
    {
        if (! is_array($snapshot)) {
            return null;
        }

        $weight = $this->positiveNumber($snapshot['weight'] ?? $snapshot['gross_weight'] ?? null);
        $length = $this->positiveNumber($snapshot['length'] ?? null);
        $height = $this->positiveNumber($snapshot['height'] ?? null);
        $breadth = $this->positiveNumber($snapshot['breadth'] ?? $snapshot['width'] ?? null);
        if ($weight === null || $length === null || $height === null || $breadth === null) {
            return null;
        }

        $weightUnit = strtolower(trim((string) ($snapshot['weight_unit'] ?? 'kg')));
        $dimensionUnit = strtolower(trim((string) ($snapshot['dimension_unit'] ?? 'cm')));
        if (isset($snapshot['weight_unit']) && $weightUnit !== 'kg') {
            return null;
        }
        if (isset($snapshot['dimension_unit']) && $dimensionUnit !== 'cm') {
            return null;
        }

        return [
            'weight' => $weight,
            'length' => $length,
            'breadth' => $breadth,
            'height' => $height,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(HardwareFulfilment $fulfilment, ?User $actor): array
    {
        $product = $this->singleAllocatedProduct($fulfilment);
        $pack = $product?->packaging;
        if ($product === null || $pack === null || ! $this->packagingIsUsable($pack)) {
            throw ValidationException::withMessages([
                'parcel' => 'Verified catalog packaging is missing.',
            ]);
        }

        return [
            'weight' => (float) $pack->gross_weight,
            'length' => (float) $pack->length,
            'breadth' => (float) $pack->breadth,
            'height' => (float) $pack->height,
            'weight_unit' => 'kg',
            'dimension_unit' => 'cm',
            'source' => 'inventory_product_packaging',
            'inventory_product_id' => $product->id,
            'packaging_id' => $pack->id,
            'verified_at' => optional($pack->verified_at)?->toIso8601String(),
            'verified_by_user_id' => $pack->verified_by_user_id,
            'snapshotted_at' => now()->toIso8601String(),
            'snapshotted_by_user_id' => $actor?->id,
        ];
    }

    private function packagingIsUsable(InventoryProductPackaging $pack): bool
    {
        return strtolower((string) $pack->weight_unit) === 'kg'
            && strtolower((string) $pack->dimension_unit) === 'cm'
            && $this->positiveNumber($pack->gross_weight) !== null
            && $this->positiveNumber($pack->length) !== null
            && $this->positiveNumber($pack->breadth) !== null
            && $this->positiveNumber($pack->height) !== null
            && $pack->verified_at !== null;
    }

    private function singleAllocatedProduct(HardwareFulfilment $fulfilment): ?InventoryProduct
    {
        $ids = $this->allocatedProductIds($fulfilment);
        if (count($ids) !== 1) {
            return null;
        }

        $fulfilment->loadMissing('serials.inventorySerial.product.packaging');
        foreach ($fulfilment->serials as $row) {
            $product = $row->inventorySerial?->product;
            if ($product !== null && $product->id === array_key_first($ids)) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @return array<int, true>
     */
    private function allocatedProductIds(HardwareFulfilment $fulfilment): array
    {
        $fulfilment->loadMissing('serials.inventorySerial');
        $ids = [];
        foreach ($fulfilment->serials as $row) {
            $productId = $row->inventorySerial?->product_id;
            if ($productId) {
                $ids[(int) $productId] = true;
            }
        }

        return $ids;
    }

    private function physicalQty(CommerceOrder $order): int
    {
        $required = 0;
        foreach ($order->items as $item) {
            if (HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                $required += (int) $item->qty;
            }
        }

        return $required;
    }

    private function boundShipment(HardwareFulfilment $fulfilment): ?Shipment
    {
        $shipment = $fulfilment->shipment;
        if ($shipment === null && $fulfilment->shipment_id !== null) {
            $shipment = Shipment::query()->find($fulfilment->shipment_id);
        }

        if ($shipment !== null && $shipment->isBound()) {
            return $shipment;
        }

        return null;
    }

    private function positiveNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number > 0 ? $number : null;
    }

    private function formatNumber(mixed $value): string
    {
        $number = (float) $value;
        $formatted = rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
