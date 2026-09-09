<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\StatutoryInvoice\BuyerGstin;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryFinancialYear;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentInvoiceService
{
    public function __construct(
        private readonly HardwareFulfilmentWorkflowService $workflow,
        private readonly HardwareIssuer $issuer,
        private readonly StatutoryInvoiceService $invoices,
        private readonly StatutoryDocumentService $documents,
        private readonly StatutoryMintEligibility $eligibility,
        private readonly HardwareInclusiveGstReconciler $gst,
    ) {}

    public function issueInvoice(HardwareFulfilment $fulfilment, ?User $actor = null): StatutoryInvoice
    {
        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot be invoiced.',
            ]);
        }

        $invoice = DB::transaction(function () use ($fulfilment, $actor): StatutoryInvoice {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->with(['commerceOrder.items'])
                ->firstOrFail();

            $existing = $this->existingInvoice($locked);
            if ($existing !== null) {
                $this->linkRecords($locked, $locked->commerceOrder, $existing);

                return $existing;
            }

            $this->workflow->assertCanIssueInvoice($locked);

            $order = $locked->commerceOrder;
            if ($order === null) {
                throw ValidationException::withMessages([
                    'fulfilment' => 'Hardware fulfilment is missing its commerce order.',
                ]);
            }

            $this->assertNoPricedServiceCompanion($order);
            $serials = $this->requireAllocatedSerials($locked, $order);
            $branch = $this->requireFulfilmentBranch($locked);
            $location = $this->issuer->require($branch->code, $order->buyer_gstin);

            if ($order->branch_code === null) {
                $order->forceFill(['branch_code' => $branch->code])->save();
            }

            $invoice = $this->invoices->mint($this->mintRequest($order, $location, $branch->id), $actor);
            $this->linkRecords($locked, $order, $invoice, $location, $serials);

            return $invoice;
        });

        $this->documents->generate($invoice);
        $this->invoices->queueEinvoiceIfEligible($invoice);

        $fresh = $fulfilment->fresh() ?? $fulfilment;
        if ($fresh->state === HardwareFulfilmentState::SerialsAllocated) {
            $this->workflow->transition(
                $fresh,
                HardwareFulfilmentState::InvoiceIssued,
                actorType: $actor !== null ? 'user' : 'system',
                actorId: $actor?->id,
                payload: [
                    'reason' => 'hardware_invoice_issued',
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ],
            );
        }

        return $invoice->load(['items', 'allocation', 'document']);
    }

    /**
     * @return list<string>
     */
    public function requireAllocatedSerials(HardwareFulfilment $fulfilment, CommerceOrder $order): array
    {
        $this->workflow->assertCanIssueInvoice($fulfilment);

        $serials = $this->workflow->allocatedSerialNumbers($fulfilment);
        if ($serials === []) {
            throw ValidationException::withMessages([
                'serials' => 'Hardware invoice issuance requires a persisted allocated serial list. Serials are not invented.',
            ]);
        }

        $normalized = [];
        foreach ($serials as $serial) {
            $value = strtoupper(trim($serial));
            if ($value === '') {
                throw ValidationException::withMessages([
                    'serials' => 'Allocated serial numbers cannot be blank.',
                ]);
            }
            if (isset($normalized[$value])) {
                throw ValidationException::withMessages([
                    'serials' => 'Duplicate allocated serials fail closed.',
                ]);
            }
            $normalized[$value] = $value;
        }

        $requiredQty = $this->physicalQuantity($order);
        if ($requiredQty < 1) {
            throw ValidationException::withMessages([
                'serials' => 'Hardware invoice issuance requires at least one physical merchandise line.',
            ]);
        }

        if (count($normalized) !== $requiredQty) {
            throw ValidationException::withMessages([
                'serials' => sprintf(
                    'Allocated serial count %d does not match physical quantity %d.',
                    count($normalized),
                    $requiredQty,
                ),
            ]);
        }

        return array_values($normalized);
    }

    private function existingInvoice(HardwareFulfilment $fulfilment): ?StatutoryInvoice
    {
        if ($fulfilment->statutory_invoice_id !== null) {
            return StatutoryInvoice::query()->find($fulfilment->statutory_invoice_id);
        }

        $order = $fulfilment->commerceOrder;
        if ($order === null) {
            return null;
        }

        return $this->invoices->findBySource(
            $order->channel,
            StatutoryInvoiceSourceType::CommerceOrder,
            $order->source_id,
        );
    }

    /**
     * @param  list<string>|null  $serials
     */
    private function linkRecords(
        HardwareFulfilment $fulfilment,
        ?CommerceOrder $order,
        StatutoryInvoice $invoice,
        ?string $location = null,
        ?array $serials = null,
    ): void {
        if ($order !== null) {
            if ($order->statutory_invoice_id === null) {
                $order->statutory_invoice_id = $invoice->id;
            }
            if ($order->status !== CommerceOrderStatus::Invoiced) {
                $order->status = CommerceOrderStatus::Invoiced;
            }
            $order->save();
        }

        $updates = [];
        if ($fulfilment->statutory_invoice_id === null) {
            $updates['statutory_invoice_id'] = $invoice->id;
        }
        if ($location !== null && $fulfilment->issuer_location === null) {
            $updates['issuer_location'] = $location;
        }
        if ($serials !== null) {
            $metadata = $fulfilment->metadata ?? [];
            if (! isset($metadata['invoice_serials'])) {
                $metadata['invoice_serials'] = $serials;
                $metadata['invoice_serials_locked_at'] = now()->toIso8601String();
                $updates['metadata'] = $metadata;
            }
        }
        if ($updates !== []) {
            $fulfilment->forceFill($updates)->save();
        }
    }

    private function requireFulfilmentBranch(HardwareFulfilment $fulfilment): InventoryBranch
    {
        if ($fulfilment->fulfilment_branch_id === null) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware invoice issuance requires a fulfilment branch. Customer state cannot substitute.',
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

    private function mintRequest(CommerceOrder $order, string $location, ?int $branchId = null): StatutoryInvoiceMintRequest
    {
        $order->loadMissing('items');
        $lines = [];
        foreach ($order->items as $item) {
            if (! HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                continue;
            }

            $description = (string) $item->description;
            if ($item->rdserviceid !== null) {
                $description .= ' (bundled RD #'.$item->rdserviceid.')';
            }

            $gst = $this->gst->reconcile($item);

            $lines[] = new StatutoryInvoiceLineDraft(
                description: $description,
                qty: (int) $item->qty,
                unitPrice: (float) $item->unit_price,
                gstPercentage: $gst->gstPercentage,
                taxTotal: $gst->taxTotal,
                lineTotal: $gst->lineTotal,
                taxableValue: $gst->taxableValue,
                discount: (float) ($item->discount ?? 0),
                sku: $item->sku,
                hsnSac: $item->hsn_sac,
            );
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Hardware invoice issuance requires priced physical merchandise lines.',
            ]);
        }

        $commercialDate = $this->eligibility->commercialDate($order);

        return new StatutoryInvoiceMintRequest(
            channel: $order->channel,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: $order->source_id,
            lines: $lines,
            sourceOrderId: $order->source_order_id ?? $order->source_id,
            sellerGstin: null,
            sellerName: null,
            buyerName: $order->customer_name,
            buyerPhone: $order->customer_phone,
            buyerGstin: BuyerGstin::normalize($order->buyer_gstin),
            billingAddress: $order->billing_address,
            placeOfSupplyState: $order->place_of_supply_state,
            discount: (float) ($order->discount ?? 0),
            paymentMethod: $order->payment_method,
            paymentReference: $order->payment_reference,
            supportOrderId: $order->support_order_id,
            branchId: $branchId,
            numberingLocation: $location,
            financialYearToken: $commercialDate !== null
                ? StatutoryFinancialYear::containing($commercialDate)->token()
                : null,
            inclusiveHardwareGst: true,
        );
    }

    private function physicalQuantity(CommerceOrder $order): int
    {
        $qty = 0;
        foreach ($order->items as $item) {
            if (HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                $qty += (int) $item->qty;
            }
        }

        return $qty;
    }

    private function assertNoPricedServiceCompanion(CommerceOrder $order): void
    {
        foreach ($order->items as $item) {
            if (HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                continue;
            }
            if ($this->isPricedServiceLine($item)) {
                throw ValidationException::withMessages([
                    'lines' => 'A second priced service line on a hardware order is not invoiced. Bundled RD must remain an annotation on the hardware line.',
                ]);
            }
        }
    }

    private function isPricedServiceLine(CommerceOrderItem $item): bool
    {
        $hsn = trim((string) $item->hsn_sac);
        if ($hsn === '' || ! str_starts_with($hsn, '99')) {
            return false;
        }

        return (float) $item->unit_price > 0 || (float) $item->line_total > 0;
    }
}
