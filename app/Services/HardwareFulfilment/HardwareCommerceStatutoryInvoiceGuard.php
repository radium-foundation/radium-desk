<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Finance Hub commerce mint must not skip hardware serial allocation.
 * Authoritative mint remains HardwareFulfilmentInvoiceService (lockForUpdate).
 */
final class HardwareCommerceStatutoryInvoiceGuard
{
    public const SERIALS_REQUIRED = 'Hardware statutory invoice generation requires completion of required serial allocation. Payment alone does not trigger the final statutory hardware invoice.';

    public function __construct(
        private readonly HardwareFulfilmentWorkflowService $workflow,
    ) {}

    public function requiresHardwareSerialPath(CommerceOrder $order): bool
    {
        return HardwareFulfilmentEligibility::requiresSerialAllocatedInvoice($order);
    }

    /**
     * @return list<string>
     */
    public function blockingErrors(CommerceOrder $order): array
    {
        if (! $this->requiresHardwareSerialPath($order)) {
            return [];
        }

        $order->loadMissing(['items', 'hardwareFulfilment']);
        $fulfilment = $order->hardwareFulfilment;
        if ($fulfilment === null) {
            return [self::SERIALS_REQUIRED];
        }

        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $order)) {
            return ['Frozen pending hardware orders cannot be invoiced.'];
        }

        if ($fulfilment->state === HardwareFulfilmentState::InvoiceIssued) {
            return [];
        }

        if ($fulfilment->state !== HardwareFulfilmentState::SerialsAllocated) {
            return [self::SERIALS_REQUIRED];
        }

        $serials = $this->workflow->allocatedSerialNumbers($fulfilment);
        $normalized = [];
        foreach ($serials as $serial) {
            $value = strtoupper(trim((string) $serial));
            if ($value === '') {
                return [self::SERIALS_REQUIRED];
            }
            if (isset($normalized[$value])) {
                return [self::SERIALS_REQUIRED];
            }
            $normalized[$value] = $value;
        }

        $requiredQty = 0;
        foreach ($order->items as $item) {
            if (HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                $requiredQty += (int) $item->qty;
            }
        }

        if ($requiredQty < 1 || $normalized === [] || count($normalized) !== $requiredQty) {
            return [self::SERIALS_REQUIRED];
        }

        return [];
    }

    public function issue(CommerceOrder $order, ?User $actor = null): StatutoryInvoice
    {
        $order->loadMissing('hardwareFulfilment');
        $fulfilment = $order->hardwareFulfilment;
        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'invoice' => self::SERIALS_REQUIRED,
            ]);
        }

        return app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment, $actor);
    }
}
