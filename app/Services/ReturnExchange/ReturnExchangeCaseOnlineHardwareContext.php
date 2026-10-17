<?php

namespace App\Services\ReturnExchange;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Models\ReturnExchangeCase;
use App\Services\ReturnExchange\Data\ResolvedOnlineHardwareReturnContext;
use Illuminate\Validation\ValidationException;

final class ReturnExchangeCaseOnlineHardwareContext
{
    public function resolve(ReturnExchangeCase $case): ResolvedOnlineHardwareReturnContext
    {
        if (! in_array($case->source_kind, [
            ReturnExchangeCaseSourceKind::EcommerceOrder,
            ReturnExchangeCaseSourceKind::EcommerceFulfilment,
        ], true)) {
            throw ValidationException::withMessages([
                'case' => 'Online hardware refund submission is only supported for ecommerce order or fulfilment cases.',
            ]);
        }

        return match ($case->source_kind) {
            ReturnExchangeCaseSourceKind::EcommerceFulfilment => $this->fromFulfilment($case),
            ReturnExchangeCaseSourceKind::EcommerceOrder => $this->fromOrder($case),
            default => throw ValidationException::withMessages(['case' => 'Unsupported source kind.']),
        };
    }

    private function fromFulfilment(ReturnExchangeCase $case): ResolvedOnlineHardwareReturnContext
    {
        if ($case->hardware_fulfilment_id === null) {
            throw ValidationException::withMessages([
                'hardware_fulfilment_id' => 'Hardware fulfilment is not linked on this case.',
            ]);
        }

        $fulfilment = HardwareFulfilment::query()->find($case->hardware_fulfilment_id);
        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'hardware_fulfilment_id' => 'Hardware fulfilment was not found.',
            ]);
        }

        if ($fulfilment->support_order_id === null) {
            throw ValidationException::withMessages([
                'order' => 'Fulfilment has no linked Desk order; cannot create an order-based refund.',
            ]);
        }

        if ($case->order_id !== null && (int) $case->order_id !== (int) $fulfilment->support_order_id) {
            throw ValidationException::withMessages([
                'order_id' => 'Case order does not match the fulfilment support order.',
            ]);
        }

        $order = Order::query()->find($fulfilment->support_order_id);
        if ($order === null) {
            throw ValidationException::withMessages([
                'order_id' => 'Desk order for this fulfilment was not found.',
            ]);
        }

        return new ResolvedOnlineHardwareReturnContext(
            order: $order,
            fulfilment: $fulfilment,
            branchId: $case->branch_id ?? $fulfilment->fulfilment_branch_id,
        );
    }

    private function fromOrder(ReturnExchangeCase $case): ResolvedOnlineHardwareReturnContext
    {
        if ($case->order_id === null) {
            throw ValidationException::withMessages([
                'order_id' => 'Desk order is not linked on this case.',
            ]);
        }

        $order = Order::query()->find($case->order_id);
        if ($order === null) {
            throw ValidationException::withMessages([
                'order_id' => 'Desk order was not found.',
            ]);
        }

        $fulfilments = HardwareFulfilment::query()
            ->where('support_order_id', $order->id)
            ->get();

        if ($fulfilments->isEmpty()) {
            throw ValidationException::withMessages([
                'hardware_fulfilment_id' => 'No hardware fulfilment is linked to this order.',
            ]);
        }

        if ($fulfilments->count() > 1) {
            throw ValidationException::withMessages([
                'hardware_fulfilment_id' => 'Multiple hardware fulfilments are linked to this order; open the case against a specific fulfilment.',
            ]);
        }

        $fulfilment = $fulfilments->first();

        return new ResolvedOnlineHardwareReturnContext(
            order: $order,
            fulfilment: $fulfilment,
            branchId: $case->branch_id ?? $fulfilment?->fulfilment_branch_id,
        );
    }
}
