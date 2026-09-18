<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventorySerial;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Support\Inventory\InventorySerialNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner-controlled cancellation for Desk duplicate fulfilments that were opened
 * after the linked support order already carries historical Admin completion evidence.
 *
 * Does not call Shiprocket mutation APIs. Preserves shipment/invoice rows.
 */
final class HardwareHistoricalDuplicateFulfilmentCancellationService
{
    public const REASON_EVENT = 'hardware_historical_duplicate_cancelled';

    public const DEFAULT_IDEMPOTENCY_PREFIX = 'historical-duplicate-cancel:';

    /**
     * @var list<HardwareFulfilmentState>
     */
    private const CANCELLABLE_STATES = [
        HardwareFulfilmentState::SerialsAllocated,
        HardwareFulfilmentState::InvoiceIssued,
        HardwareFulfilmentState::ShipmentCreated,
    ];

    public function __construct(
        private readonly HardwareFulfilmentWorkflowService $workflow,
        private readonly InventoryStockService $stock,
        private readonly StatutoryInvoiceService $invoices,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     idempotent: bool,
     *     fulfilment_id: int,
     *     source_id: string,
     *     invoice_number: string|null,
     *     invoice_status: string|null,
     *     released_serials: list<string>,
     *     shipment_id: int|null,
     *     historical_transaction_id: string|null,
     * }
     */
    public function cancel(
        HardwareFulfilment $fulfilment,
        User $actor,
        string $reason,
        string $idempotencyKey,
        ?string $historicalAdminInvoiceReference = null,
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A cancellation reason is required.',
            ]);
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key is required.',
            ]);
        }

        return DB::transaction(function () use ($fulfilment, $actor, $reason, $idempotencyKey, $historicalAdminInvoiceReference): array {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->existingCancellationResult($locked, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }

            $locked->loadMissing(['commerceOrder', 'shipment', 'statutoryInvoice', 'serials.inventorySerial.product']);
            $this->assertEligible($locked);

            $support = $this->requireSupportOrder($locked);
            $invoice = $this->requireDuplicateInvoice($locked);
            $branch = $this->requireFulfilmentBranch($locked);
            $releasedSerials = $this->releaseAllocatedSerials($locked, $branch, $actor);

            $cancelledInvoice = $this->invoices->cancel(
                $invoice,
                $actor,
                $this->invoiceCancelReason($reason, $historicalAdminInvoiceReference, $support),
            );

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['historical_duplicate_cancellation'] = [
                'idempotency_key' => $idempotencyKey,
                'cancelled_at' => now()->toIso8601String(),
                'cancelled_by_user_id' => $actor->id,
                'reason' => $reason,
                'historical_admin_invoice_reference' => $historicalAdminInvoiceReference,
                'historical_support_transaction_id' => (string) $support->transaction_id,
                'historical_support_completed_at' => $support->completed_at?->toIso8601String(),
                'historical_support_serial' => (string) $support->serial_number,
                'desk_duplicate_invoice_number' => (string) $cancelledInvoice->invoice_number,
                'desk_duplicate_invoice_id' => (int) $cancelledInvoice->id,
                'released_serials' => $releasedSerials,
                'preserved_shipment_id' => $locked->shipment_id,
                'preserved_provider_shipment_id' => $locked->provider_shipment_id,
            ];
            $locked->forceFill(['metadata' => $metadata])->save();

            $this->workflow->transition(
                $locked,
                HardwareFulfilmentState::CancelledHistoricalDuplicate,
                actorType: 'user',
                actorId: $actor->id,
                payload: [
                    'reason' => self::REASON_EVENT,
                    'idempotency_key' => $idempotencyKey,
                    'released_serials' => $releasedSerials,
                    'invoice_number' => $cancelledInvoice->invoice_number,
                    'historical_transaction_id' => (string) $support->transaction_id,
                    'historical_admin_invoice_reference' => $historicalAdminInvoiceReference,
                ],
            );

            return [
                'ok' => true,
                'idempotent' => false,
                'fulfilment_id' => (int) $locked->id,
                'source_id' => (string) $locked->source_id,
                'invoice_number' => (string) $cancelledInvoice->invoice_number,
                'invoice_status' => $cancelledInvoice->status->value,
                'released_serials' => $releasedSerials,
                'shipment_id' => $locked->shipment_id !== null ? (int) $locked->shipment_id : null,
                'historical_transaction_id' => (string) $support->transaction_id,
            ];
        });
    }

    public function assertEligible(HardwareFulfilment $fulfilment): void
    {
        if ($fulfilment->state === HardwareFulfilmentState::CancelledHistoricalDuplicate) {
            return;
        }

        if (! in_array($fulfilment->state, self::CANCELLABLE_STATES, true)) {
            throw ValidationException::withMessages([
                'state' => 'Historical duplicate cancellation requires a pre-AWB Desk fulfilment state.',
            ]);
        }

        if (! filled($fulfilment->source_id)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Hardware fulfilment is missing a source id.',
            ]);
        }

        $support = $this->requireSupportOrder($fulfilment);
        if ($support->completed_at === null) {
            throw ValidationException::withMessages([
                'historical' => 'Historical duplicate cancellation requires a completed support order.',
            ]);
        }

        if (! filled(trim((string) $support->transaction_id))) {
            throw ValidationException::withMessages([
                'historical' => 'Historical duplicate cancellation requires historical shipment evidence on the support order.',
            ]);
        }

        if ($fulfilment->invoice_issued_at !== null
            && $support->completed_at->greaterThanOrEqualTo($fulfilment->invoice_issued_at)) {
            throw ValidationException::withMessages([
                'historical' => 'Support-order completion must predate the duplicate Desk invoice.',
            ]);
        }

        if (filled($fulfilment->awb) || filled($fulfilment->provider_awb)) {
            throw ValidationException::withMessages([
                'shipping' => 'Historical duplicate cancellation refuses fulfilments with an assigned AWB.',
            ]);
        }

        $shipment = $fulfilment->shipment;
        if ($shipment !== null) {
            $this->assertShipmentPreservable($shipment);
        }

        $invoice = $fulfilment->statutoryInvoice;
        if ($invoice === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Historical duplicate cancellation requires a Desk statutory invoice.',
            ]);
        }

        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            throw ValidationException::withMessages([
                'invoice' => 'The duplicate Desk invoice is already cancelled.',
            ]);
        }

        $allocated = $fulfilment->serials
            ->where('status', HardwareFulfilmentSerialStatus::Allocated);
        if ($allocated->isEmpty()) {
            throw ValidationException::withMessages([
                'serials' => 'Historical duplicate cancellation requires allocated serial rows to release.',
            ]);
        }

        foreach ($allocated as $row) {
            $this->assertSerialOwnedOnlyByFulfilment($row, $fulfilment);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingCancellationResult(HardwareFulfilment $fulfilment, string $idempotencyKey): ?array
    {
        if ($fulfilment->state !== HardwareFulfilmentState::CancelledHistoricalDuplicate) {
            return null;
        }

        $metadata = is_array($fulfilment->metadata) ? $fulfilment->metadata : [];
        $record = $metadata['historical_duplicate_cancellation'] ?? null;
        if (! is_array($record)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Cancelled fulfilment is missing historical duplicate cancellation metadata.',
            ]);
        }

        if (($record['idempotency_key'] ?? null) !== $idempotencyKey) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This fulfilment was already cancelled under a different idempotency key.',
            ]);
        }

        $invoice = $fulfilment->statutoryInvoice;

        return [
            'ok' => true,
            'idempotent' => true,
            'fulfilment_id' => (int) $fulfilment->id,
            'source_id' => (string) $fulfilment->source_id,
            'invoice_number' => $invoice !== null ? (string) $invoice->invoice_number : null,
            'invoice_status' => $invoice !== null ? $invoice->status->value : null,
            'released_serials' => array_values(array_map(
                static fn ($serial): string => (string) $serial,
                $record['released_serials'] ?? [],
            )),
            'shipment_id' => $fulfilment->shipment_id !== null ? (int) $fulfilment->shipment_id : null,
            'historical_transaction_id' => (string) ($record['historical_support_transaction_id'] ?? ''),
        ];
    }

    private function requireSupportOrder(HardwareFulfilment $fulfilment): Order
    {
        $supportOrderId = $fulfilment->support_order_id ?? $fulfilment->commerceOrder?->support_order_id;
        if ($supportOrderId === null) {
            throw ValidationException::withMessages([
                'support_order' => 'Hardware fulfilment is missing its linked support order.',
            ]);
        }

        $support = Order::query()->find($supportOrderId);
        if ($support === null) {
            throw ValidationException::withMessages([
                'support_order' => 'Linked support order was not found.',
            ]);
        }

        return $support;
    }

    private function requireDuplicateInvoice(HardwareFulfilment $fulfilment): StatutoryInvoice
    {
        $invoice = $fulfilment->statutoryInvoice;
        if ($invoice === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Hardware fulfilment is missing its statutory invoice.',
            ]);
        }

        return $invoice;
    }

    private function requireFulfilmentBranch(HardwareFulfilment $fulfilment): InventoryBranch
    {
        $branchId = $fulfilment->fulfilment_branch_id;
        if ($branchId === null) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware fulfilment is missing its fulfilment branch.',
            ]);
        }

        $branch = InventoryBranch::query()->find($branchId);
        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware fulfilment branch was not found.',
            ]);
        }

        return $branch;
    }

    private function assertShipmentPreservable(Shipment $shipment): void
    {
        if (filled($shipment->awb)) {
            throw ValidationException::withMessages([
                'shipping' => 'Historical duplicate cancellation refuses shipments with an AWB.',
            ]);
        }

        $normalized = trim((string) ($shipment->provider_track_normalized ?? ''));
        if ($normalized !== '' && ! in_array($normalized, ['unknown', 'awb_assigned'], true)) {
            throw ValidationException::withMessages([
                'shipping' => 'Historical duplicate cancellation refuses in-flight provider tracking.',
            ]);
        }
    }

    private function assertSerialOwnedOnlyByFulfilment(HardwareFulfilmentSerial $row, HardwareFulfilment $fulfilment): void
    {
        $serialId = (int) $row->inventory_serial_id;
        $conflict = HardwareFulfilmentSerial::query()
            ->where('inventory_serial_id', $serialId)
            ->where('status', HardwareFulfilmentSerialStatus::Allocated)
            ->where('hardware_fulfilment_id', '!=', $fulfilment->id)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'serials' => 'Serial '.$row->serial_number.' is allocated to another active fulfilment.',
            ]);
        }

        $inventorySerial = $row->inventorySerial ?? InventorySerial::query()->find($serialId);
        if ($inventorySerial === null) {
            throw ValidationException::withMessages([
                'serials' => 'Allocated inventory serial '.$row->serial_number.' was not found.',
            ]);
        }

        if ($inventorySerial->status !== InventorySerialStatus::Sold) {
            throw ValidationException::withMessages([
                'serials' => 'Serial '.$row->serial_number.' is not in sold inventory status.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function releaseAllocatedSerials(
        HardwareFulfilment $fulfilment,
        InventoryBranch $branch,
        User $actor,
    ): array {
        $released = [];

        $rows = $fulfilment->serials
            ->where('status', HardwareFulfilmentSerialStatus::Allocated)
            ->values();

        foreach ($rows as $row) {
            $inventorySerial = $row->inventorySerial ?? InventorySerial::query()->findOrFail($row->inventory_serial_id);
            $product = $inventorySerial->product;
            if ($product === null) {
                throw ValidationException::withMessages([
                    'serials' => 'Inventory product missing for serial '.$row->serial_number.'.',
                ]);
            }

            $this->assertSerialOwnedOnlyByFulfilment($row, $fulfilment);

            $row->forceFill([
                'status' => HardwareFulfilmentSerialStatus::Released,
            ])->save();

            $this->stock->restoreSerialFromSale($inventorySerial, $branch);
            $this->stock->recordMovement(
                type: InventoryMovementType::SaleCancel,
                product: $product,
                branch: $branch,
                qty: 1,
                actor: $actor,
                variant: $inventorySerial->variant,
                serial: $inventorySerial,
                fromStatus: InventorySerialStatus::Sold,
                toStatus: InventorySerialStatus::Available,
                notes: 'hardware_historical_duplicate_cancel:'.$fulfilment->id,
            );

            $released[] = InventorySerialNumber::normalize((string) $row->serial_number);
        }

        return $released;
    }

    private function invoiceCancelReason(
        string $reason,
        ?string $historicalAdminInvoiceReference,
        Order $support,
    ): string {
        $parts = [
            'Historical duplicate Desk fulfilment cancellation.',
            $reason,
            'Historical support transaction: '.trim((string) $support->transaction_id).'.',
        ];

        if ($historicalAdminInvoiceReference !== null && trim($historicalAdminInvoiceReference) !== '') {
            $parts[] = 'Historical Admin invoice reference: '.trim($historicalAdminInvoiceReference).'.';
        }

        return implode(' ', $parts);
    }
}
