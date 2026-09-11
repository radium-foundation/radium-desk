<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Models\EInvoiceRecord;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventorySerial;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Support\Inventory\InventorySerialNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentSerialCorrectionService
{
    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly HardwareSkuMapService $skuMap,
        private readonly StatutoryDocumentService $documents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        HardwareFulfilment $fulfilment,
        string $incorrectSerial,
        string $correctSerial,
    ): array {
        $context = $this->buildContext($fulfilment, $incorrectSerial, $correctSerial);

        return [
            'ok' => true,
            'dry_run' => true,
            'source_id' => $context['source_id'],
            'hardware_fulfilment_id' => $context['fulfilment']->id,
            'from_serial' => $context['from_serial'],
            'to_serial' => $context['to_serial'],
            'invoice_number' => $context['invoice']->invoice_number,
            'invoice_status' => $context['invoice']->status->value,
            'irn_status' => $context['irn_status'],
            'fulfilment_state' => $context['fulfilment']->state->value,
            'awb' => $context['fulfilment']->awb,
        ];
    }

    public function correct(
        HardwareFulfilment $fulfilment,
        string $incorrectSerial,
        string $correctSerial,
        User $actor,
        ?string $reason = null,
    ): HardwareFulfilment {
        $context = $this->buildContext($fulfilment, $incorrectSerial, $correctSerial);

        $corrected = DB::transaction(function () use ($context, $actor, $reason): HardwareFulfilment {
            $locked = HardwareFulfilment::query()
                ->whereKey($context['fulfilment']->id)
                ->lockForUpdate()
                ->with(['commerceOrder.items', 'serials'])
                ->firstOrFail();

            $allocation = $this->requireMatchingAllocation($locked, $context['from_serial']);
            $wrongStock = $this->stock->lockSerialById((int) $allocation->inventory_serial_id);
            $correctStock = $this->stock->lockSerialByNumber($context['to_serial']);

            $this->assertWrongSerialMatches($wrongStock, $context['from_serial']);
            $this->assertCorrectSerialEligible(
                $correctStock,
                $context['to_serial'],
                $wrongStock,
                $allocation,
            );

            $branch = $this->requireBranch($locked);
            $order = $locked->commerceOrder;
            if ($order === null) {
                throw ValidationException::withMessages([
                    'fulfilment' => 'Hardware fulfilment is missing its commerce order.',
                ]);
            }
            $item = $order->items->firstWhere('id', $allocation->commerce_order_item_id);
            if ($item === null) {
                throw ValidationException::withMessages([
                    'serials' => 'Hardware fulfilment serial row is missing its commerce line.',
                ]);
            }
            $product = $this->skuMap->requireProductForItem($order->channel, $item);

            $this->stock->restoreSerialFromSale($wrongStock, $branch);
            $this->stock->recordMovement(
                type: InventoryMovementType::SaleCancel,
                product: $product,
                branch: $branch,
                qty: 1,
                actor: $actor,
                variant: $wrongStock->variant,
                serial: $wrongStock,
                fromStatus: InventorySerialStatus::Sold,
                toStatus: InventorySerialStatus::Available,
                notes: sprintf(
                    'hardware_fulfilment_serial_correction:%d:%s->%s',
                    $locked->id,
                    $context['from_serial'],
                    $context['to_serial'],
                ),
            );

            $allocation->forceFill([
                'inventory_serial_id' => $correctStock->id,
                'serial_number' => $context['to_serial'],
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ])->save();

            $this->stock->markSerialSold($correctStock, $branch);
            $this->stock->recordMovement(
                type: InventoryMovementType::Sale,
                product: $product,
                branch: $branch,
                qty: -1,
                actor: $actor,
                variant: $correctStock->variant,
                serial: $correctStock,
                fromStatus: InventorySerialStatus::Available,
                toStatus: InventorySerialStatus::Sold,
                notes: sprintf(
                    'hardware_fulfilment_serial_correction:%d:%s->%s',
                    $locked->id,
                    $context['from_serial'],
                    $context['to_serial'],
                ),
            );

            $metadata = $locked->metadata ?? [];
            $metadata['invoice_serials'] = [$context['to_serial']];
            $metadata['invoice_serials_corrected_at'] = now()->toIso8601String();
            $metadata['serial_corrections'] = array_values(array_merge(
                is_array($metadata['serial_corrections'] ?? null) ? $metadata['serial_corrections'] : [],
                [[
                    'from' => $context['from_serial'],
                    'to' => $context['to_serial'],
                    'corrected_at' => now()->toIso8601String(),
                    'actor_id' => $actor->id,
                    'reason' => $reason ?? 'hardware_fulfilment_serial_correction',
                ]],
            ));
            $locked->forceFill(['metadata' => $metadata])->save();

            HardwareFulfilmentEvent::query()->create([
                'hardware_fulfilment_id' => $locked->id,
                'from_state' => $locked->state,
                'to_state' => $locked->state,
                'actor_type' => 'user',
                'actor_id' => $actor->id,
                'payload' => [
                    'reason' => 'hardware_fulfilment_serial_corrected',
                    'from_serial' => $context['from_serial'],
                    'to_serial' => $context['to_serial'],
                    'invoice_id' => $context['invoice']->id,
                    'invoice_number' => $context['invoice']->invoice_number,
                ],
                'created_at' => now(),
            ]);

            return $locked->fresh(['commerceOrder.items', 'serials']) ?? $locked;
        });

        $this->documents->regenerateForHardwareSerialCorrection($context['invoice']);

        return $corrected;
    }

    /**
     * @return array{
     *     fulfilment: HardwareFulfilment,
     *     invoice: StatutoryInvoice,
     *     from_serial: string,
     *     to_serial: string,
     *     source_id: string,
     *     irn_status: string|null
     * }
     */
    private function buildContext(
        HardwareFulfilment $fulfilment,
        string $incorrectSerial,
        string $correctSerial,
    ): array {
        $from = InventorySerialNumber::normalize($incorrectSerial);
        $to = InventorySerialNumber::normalize($correctSerial);

        if ($from === '' || $to === '') {
            throw ValidationException::withMessages([
                'serials' => 'Both incorrect and correct serial numbers are required.',
            ]);
        }

        if ($from === $to) {
            throw ValidationException::withMessages([
                'serials' => 'Correct serial must differ from the incorrect serial.',
            ]);
        }

        $fresh = $fulfilment->fresh(['commerceOrder.items', 'serials']) ?? $fulfilment;
        $invoice = $this->requireInvoice($fresh);
        $this->assertIrnAllowsCorrection($invoice);
        $this->requireMatchingAllocation($fresh, $from);

        $correctStock = InventorySerial::query()->where('serial_number', $to)->first();
        if ($correctStock === null) {
            throw ValidationException::withMessages([
                'serials' => "Correct serial {$to} was not found in inventory.",
            ]);
        }

        $irn = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();

        return [
            'fulfilment' => $fresh,
            'invoice' => $invoice,
            'from_serial' => $from,
            'to_serial' => $to,
            'source_id' => (string) $fresh->source_id,
            'irn_status' => $this->irnStatusValue($irn),
        ];
    }

    private function irnStatusValue(?EInvoiceRecord $irn): ?string
    {
        if ($irn === null) {
            return null;
        }

        $status = $irn->status;

        return $status instanceof EInvoiceRecordStatus ? $status->value : (string) $status;
    }

    private function requireInvoice(HardwareFulfilment $fulfilment): StatutoryInvoice
    {
        if ($fulfilment->statutory_invoice_id === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Hardware fulfilment has no statutory invoice to correct.',
            ]);
        }

        $invoice = StatutoryInvoice::query()->find($fulfilment->statutory_invoice_id);
        if ($invoice === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Hardware fulfilment invoice record is missing.',
            ]);
        }

        return $invoice;
    }

    private function assertIrnAllowsCorrection(StatutoryInvoice $invoice): void
    {
        $irn = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        if ($irn === null) {
            return;
        }

        $status = $irn->status;
        if (! $status instanceof EInvoiceRecordStatus) {
            $status = EInvoiceRecordStatus::tryFrom((string) $status);
        }

        if ($status === EInvoiceRecordStatus::Submitted) {
            throw ValidationException::withMessages([
                'invoice' => 'IRN-submitted invoices cannot be corrected in place. Use the statutory cancellation/reissue workflow.',
            ]);
        }
    }

    private function requireMatchingAllocation(HardwareFulfilment $fulfilment, string $fromSerial): HardwareFulfilmentSerial
    {
        $allocated = $fulfilment->serials
            ->where('status', HardwareFulfilmentSerialStatus::Allocated)
            ->values();

        if ($allocated->count() !== 1) {
            throw ValidationException::withMessages([
                'serials' => 'Serial correction currently supports exactly one allocated serial per fulfilment.',
            ]);
        }

        /** @var HardwareFulfilmentSerial $row */
        $row = $allocated->first();
        $current = InventorySerialNumber::normalize((string) $row->serial_number);
        if ($current !== $fromSerial) {
            throw ValidationException::withMessages([
                'serials' => sprintf(
                    'Expected incorrect serial %s but fulfilment currently has %s.',
                    $fromSerial,
                    $current !== '' ? $current : 'none',
                ),
            ]);
        }

        return $row;
    }

    private function assertWrongSerialMatches(InventorySerial $serial, string $fromSerial): void
    {
        if (InventorySerialNumber::normalize((string) $serial->serial_number) !== $fromSerial) {
            throw ValidationException::withMessages([
                'serials' => 'Incorrect inventory serial no longer matches the fulfilment allocation.',
            ]);
        }

        if ($serial->status !== InventorySerialStatus::Sold) {
            throw ValidationException::withMessages([
                'serials' => "Incorrect serial {$fromSerial} is not marked sold in inventory.",
            ]);
        }
    }

    private function assertCorrectSerialEligible(
        InventorySerial $serial,
        string $toSerial,
        InventorySerial $wrongSerial,
        HardwareFulfilmentSerial $allocation,
    ): void {
        if ((int) $serial->product_id !== (int) $wrongSerial->product_id) {
            throw ValidationException::withMessages([
                'serials' => "Correct serial {$toSerial} belongs to the wrong product.",
            ]);
        }

        if ($serial->status !== InventorySerialStatus::Available) {
            throw ValidationException::withMessages([
                'serials' => "Correct serial {$toSerial} is not available in inventory.",
            ]);
        }

        $taken = HardwareFulfilmentSerial::query()
            ->whereKeyNot($allocation->id)
            ->where(function ($query) use ($serial, $toSerial): void {
                $query->where('inventory_serial_id', $serial->id)
                    ->orWhere('serial_number', $toSerial);
            })
            ->where('status', HardwareFulfilmentSerialStatus::Allocated)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'serials' => "Correct serial {$toSerial} is already allocated to another hardware fulfilment.",
            ]);
        }
    }

    private function requireBranch(HardwareFulfilment $fulfilment): InventoryBranch
    {
        if ($fulfilment->fulfilment_branch_id === null) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware fulfilment branch is required for serial correction.',
            ]);
        }

        $branch = InventoryBranch::query()->find($fulfilment->fulfilment_branch_id);
        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware fulfilment branch is missing or inactive.',
            ]);
        }

        return $branch;
    }
}
