<?php

namespace App\Services\ReturnExchange;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Models\HardwareFulfilment;
use App\Models\InventorySale;
use App\Models\Order;
use App\Services\ReturnExchange\Data\ResolvedReturnExchangeCaseSource;
use Illuminate\Validation\ValidationException;

final class ReturnExchangeCaseSourceResolver
{
    public function resolve(
        ReturnExchangeCaseSourceKind $kind,
        ?int $inventorySaleId,
        ?int $orderId,
        ?int $hardwareFulfilmentId,
    ): ResolvedReturnExchangeCaseSource {
        return match ($kind) {
            ReturnExchangeCaseSourceKind::PosSale => $this->resolvePosSale($inventorySaleId),
            ReturnExchangeCaseSourceKind::EcommerceOrder => $this->resolveOrder($orderId, ReturnExchangeCaseSourceKind::EcommerceOrder),
            ReturnExchangeCaseSourceKind::ServiceOrder => $this->resolveOrder($orderId, ReturnExchangeCaseSourceKind::ServiceOrder),
            ReturnExchangeCaseSourceKind::EcommerceFulfilment => $this->resolveFulfilment($hardwareFulfilmentId, $orderId),
        };
    }

    private function resolvePosSale(?int $inventorySaleId): ResolvedReturnExchangeCaseSource
    {
        if ($inventorySaleId === null || $inventorySaleId < 1) {
            throw ValidationException::withMessages([
                'inventory_sale_id' => 'Select a POS sale.',
            ]);
        }

        $sale = InventorySale::query()->find($inventorySaleId);
        if ($sale === null) {
            throw ValidationException::withMessages([
                'inventory_sale_id' => 'POS sale was not found.',
            ]);
        }

        if ($sale->branch_id === null) {
            throw ValidationException::withMessages([
                'inventory_sale_id' => 'POS sale has no branch; cannot open a return case.',
            ]);
        }

        return new ResolvedReturnExchangeCaseSource(
            kind: ReturnExchangeCaseSourceKind::PosSale,
            branchId: (int) $sale->branch_id,
            inventorySaleId: $sale->id,
            orderId: null,
            hardwareFulfilmentId: null,
        );
    }

    private function resolveOrder(?int $orderId, ReturnExchangeCaseSourceKind $kind): ResolvedReturnExchangeCaseSource
    {
        if ($orderId === null || $orderId < 1) {
            throw ValidationException::withMessages([
                'order_id' => 'Select an order.',
            ]);
        }

        $order = Order::query()->find($orderId);
        if ($order === null) {
            throw ValidationException::withMessages([
                'order_id' => 'Order was not found.',
            ]);
        }

        return new ResolvedReturnExchangeCaseSource(
            kind: $kind,
            branchId: null,
            inventorySaleId: null,
            orderId: $order->id,
            hardwareFulfilmentId: null,
        );
    }

    private function resolveFulfilment(?int $hardwareFulfilmentId, ?int $orderId): ResolvedReturnExchangeCaseSource
    {
        if ($hardwareFulfilmentId === null || $hardwareFulfilmentId < 1) {
            throw ValidationException::withMessages([
                'hardware_fulfilment_id' => 'Select a hardware fulfilment.',
            ]);
        }

        $fulfilment = HardwareFulfilment::query()->find($hardwareFulfilmentId);
        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'hardware_fulfilment_id' => 'Hardware fulfilment was not found.',
            ]);
        }

        if ($orderId !== null && $fulfilment->support_order_id !== null && (int) $orderId !== (int) $fulfilment->support_order_id) {
            throw ValidationException::withMessages([
                'order_id' => 'Order does not match the selected fulfilment.',
            ]);
        }

        $resolvedOrderId = $fulfilment->support_order_id !== null
            ? (int) $fulfilment->support_order_id
            : ($orderId !== null && $orderId > 0 ? $orderId : null);

        return new ResolvedReturnExchangeCaseSource(
            kind: ReturnExchangeCaseSourceKind::EcommerceFulfilment,
            branchId: $fulfilment->fulfilment_branch_id !== null ? (int) $fulfilment->fulfilment_branch_id : null,
            inventorySaleId: null,
            orderId: $resolvedOrderId,
            hardwareFulfilmentId: $fulfilment->id,
        );
    }
}
