<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\EInvoiceRecord;
use App\Models\FinanceJournal;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StatutoryInvoiceService
{
    public const ATTEMPTS = 5;

    public function __construct(
        private readonly StatutoryInvoiceNumberingService $numbering,
        private readonly StatutoryInvoiceAccountingPolicy $accounting,
        private readonly StatutoryMintEligibility $eligibility,
        private readonly StatutoryDocumentService $documents,
        private readonly EInvoiceEligibility $einvoiceEligibility,
        private readonly EInvoiceOutboxWriter $einvoiceOutbox,
    ) {}

    public function findBySource(
        StatutoryInvoiceChannel $channel,
        StatutoryInvoiceSourceType $sourceType,
        string $sourceId,
    ): ?StatutoryInvoice {
        return StatutoryInvoice::query()
            ->where('channel', $channel->value)
            ->where('source_type', $sourceType->value)
            ->where('source_id', $sourceId)
            ->first();
    }

    public function mint(StatutoryInvoiceMintRequest $request, ?User $actor = null): StatutoryInvoice
    {
        $this->accounting->assertJournalsMustNotPost();
        $this->assertLines($request);
        $this->assertInternalReceiptIsNotReused($request);

        $existing = $this->findBySource($request->channel, $request->sourceType, $request->sourceId);
        if ($existing !== null) {
            return $existing->load(['items', 'allocation']);
        }

        try {
            return DB::transaction(function () use ($request, $actor): StatutoryInvoice {
                $again = $this->findBySource($request->channel, $request->sourceType, $request->sourceId);
                if ($again !== null) {
                    return $again->load(['items', 'allocation']);
                }

                $allocation = $this->numbering->allocate($request->idempotencyKey(), $actor);

                if ($request->internalReceiptNumber !== null
                    && $allocation->allocated_number === $request->internalReceiptNumber) {
                    throw ValidationException::withMessages([
                        'invoice_number' => 'The internal POS receipt number cannot be used as the statutory invoice number.',
                    ]);
                }

                $totals = $this->totals($request);
                $documentType = StatutoryInvoiceDocumentType::tryFrom((string) config('statutory_invoices.document_type', 'tax_invoice'))
                    ?? StatutoryInvoiceDocumentType::TaxInvoice;

                $invoice = StatutoryInvoice::query()->create([
                    'invoice_number' => $allocation->allocated_number,
                    'sequence_allocation_id' => $allocation->id,
                    'document_type' => $documentType,
                    'status' => StatutoryInvoiceStatus::Issued,
                    'channel' => $request->channel,
                    'source_type' => $request->sourceType->value,
                    'source_id' => $request->sourceId,
                    'source_order_id' => $request->sourceOrderId,
                    'idempotency_key' => $request->idempotencyKey(),
                    'inventory_sale_id' => $request->inventorySaleId,
                    'support_order_id' => $request->supportOrderId,
                    'branch_id' => $request->branchId,
                    'seller_gstin' => $request->sellerGstin,
                    'seller_name' => $request->sellerName,
                    'buyer_name' => $request->buyerName,
                    'buyer_phone' => $request->buyerPhone,
                    'buyer_gstin' => $request->buyerGstin,
                    'billing_address' => $request->billingAddress,
                    'place_of_supply_state' => $request->placeOfSupplyState,
                    'taxable_value' => $totals['taxable_value'],
                    'discount' => $request->discount,
                    'tax_total' => $totals['tax_total'],
                    'cgst' => $totals['cgst'],
                    'sgst' => $totals['sgst'],
                    'igst' => $totals['igst'],
                    'rounding' => 0,
                    'invoice_value' => $totals['invoice_value'],
                    'payment_method' => $request->paymentMethod,
                    'payment_reference' => $request->paymentReference,
                    'finance_journal_id' => null,
                    'issued_by' => $actor?->id,
                    'issued_at' => now(),
                ]);

                foreach ($request->lines as $index => $line) {
                    StatutoryInvoiceItem::query()->create([
                        'invoice_id' => $invoice->id,
                        'line_no' => $index + 1,
                        'sku' => $line->sku,
                        'description' => $line->description,
                        'hsn_sac' => $line->hsnSac,
                        'qty' => $line->qty,
                        'unit_price' => $line->unitPrice,
                        'discount' => $line->discount,
                        'gst_percentage' => $line->gstPercentage,
                        'taxable_value' => $line->taxableValue,
                        'tax_total' => $line->taxTotal,
                        'cgst' => $line->cgst,
                        'sgst' => $line->sgst,
                        'igst' => $line->igst,
                        'line_total' => $line->lineTotal,
                    ]);
                }

                if ($allocation->invoice_id === null) {
                    $allocation->update(['invoice_id' => $invoice->id]);
                }

                if ($request->inventorySaleId !== null && Schema::hasTable('inventory_sales')) {
                    DB::table('inventory_sales')
                        ->where('id', $request->inventorySaleId)
                        ->whereNull('statutory_invoice_id')
                        ->update(['statutory_invoice_id' => $invoice->id]);
                }

                $this->assertNoFinanceJournal($invoice);

                return $invoice->load(['items', 'allocation']);
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findBySource($request->channel, $request->sourceType, $request->sourceId);
            if ($existing !== null) {
                return $existing->load(['items', 'allocation']);
            }

            throw $exception;
        }
    }

    public function issueFromPosSale(InventorySale $sale, ?User $actor = null): StatutoryInvoice
    {
        if ($sale->statutory_invoice_id !== null) {
            $existing = StatutoryInvoice::query()->find($sale->statutory_invoice_id);
            if ($existing === null) {
                throw ValidationException::withMessages([
                    'sale' => 'The POS sale already links a statutory invoice that could not be loaded. No new invoice number was allocated.',
                ]);
            }

            $this->generateDocumentSafely($existing);
            $this->queueEinvoiceIfEligible($existing);

            return $existing->load(['items', 'allocation', 'document']);
        }

        $this->eligibility->assertSaleCanMint($sale);
        $sale->loadMissing(['lines.product', 'lines.variant', 'customer']);

        $lines = [];
        foreach ($sale->lines as $line) {
            $qty = (int) $line->qty;
            $unit = (float) $line->unit_price;
            $discount = (float) $line->discount;
            $taxable = round(($unit * $qty) - $discount, 2);

            $lines[] = new StatutoryInvoiceLineDraft(
                description: $line->catalogLabel(),
                qty: $qty,
                unitPrice: $unit,
                gstPercentage: (float) $line->gst_percentage,
                taxTotal: (float) $line->tax,
                lineTotal: (float) $line->line_total,
                taxableValue: $taxable,
                discount: $discount,
                sku: $line->variant?->sku ?? $line->product?->sku,
                hsnSac: $line->product?->hsn_code,
            );
        }

        $headerDiscount = round((float) $sale->discount - $sale->lines->sum(fn ($line) => (float) $line->discount), 2);
        if ($headerDiscount < 0) {
            $headerDiscount = 0.0;
        }

        $invoice = $this->mint(new StatutoryInvoiceMintRequest(
            channel: StatutoryInvoiceChannel::DeskPos,
            sourceType: StatutoryInvoiceSourceType::InventorySale,
            sourceId: (string) $sale->id,
            lines: $lines,
            sourceOrderId: $sale->sale_no,
            inventorySaleId: $sale->id,
            branchId: $sale->branch_id,
            sellerGstin: $this->deskSellerGstin(),
            sellerName: $this->deskSellerName(),
            buyerName: $sale->customer?->name,
            buyerPhone: $sale->customer?->phone,
            buyerGstin: BuyerGstin::normalize($sale->buyer_gstin),
            billingAddress: $sale->billing_address,
            placeOfSupplyState: $sale->place_of_supply_state,
            discount: $headerDiscount,
            paymentMethod: $sale->payment_method,
            paymentReference: $sale->payment_reference,
            internalReceiptNumber: $sale->invoice_number,
        ), $actor);

        $this->generateDocumentSafely($invoice);
        $this->queueEinvoiceIfEligible($invoice);

        return $invoice->load(['items', 'allocation', 'document']);
    }

    public function issueFromCommerceOrder(CommerceOrder $order, ?User $actor = null): StatutoryInvoice
    {
        $this->eligibility->assertOrderCanMint($order);

        $existing = $this->findBySource(
            $order->channel,
            StatutoryInvoiceSourceType::CommerceOrder,
            $order->source_id,
        );
        if ($existing !== null) {
            $this->linkCommerceOrder($order, $existing);
            $this->generateDocumentSafely($existing);
            $this->queueEinvoiceIfEligible($existing);

            return $existing->load(['items', 'allocation', 'document']);
        }

        $order->loadMissing('items');
        $lines = [];
        foreach ($order->items as $line) {
            $lines[] = new StatutoryInvoiceLineDraft(
                description: (string) $line->description,
                qty: (int) $line->qty,
                unitPrice: (float) $line->unit_price,
                gstPercentage: (float) $line->gst_percentage,
                taxTotal: (float) $line->tax_total,
                lineTotal: (float) $line->line_total,
                taxableValue: (float) $line->taxable_value,
                discount: (float) ($line->discount ?? 0),
                sku: $line->sku,
                hsnSac: $line->hsn_sac,
                cgst: $line->cgst !== null ? (float) $line->cgst : null,
                sgst: $line->sgst !== null ? (float) $line->sgst : null,
                igst: $line->igst !== null ? (float) $line->igst : null,
            );
        }

        $invoice = $this->mint(new StatutoryInvoiceMintRequest(
            channel: $order->channel,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: $order->source_id,
            lines: $lines,
            sourceOrderId: $order->source_order_id ?? $order->source_id,
            sellerGstin: $this->deskSellerGstin(),
            sellerName: $this->deskSellerName(),
            buyerName: $order->customer_name,
            buyerPhone: $order->customer_phone,
            buyerGstin: BuyerGstin::normalize($order->buyer_gstin),
            billingAddress: $order->billing_address,
            placeOfSupplyState: $order->place_of_supply_state,
            discount: (float) ($order->discount ?? 0),
            paymentMethod: $order->payment_method,
            paymentReference: $order->payment_reference,
        ), $actor);

        $this->linkCommerceOrder($order, $invoice);
        $this->generateDocumentSafely($invoice);
        $this->queueEinvoiceIfEligible($invoice);

        return $invoice->load(['items', 'allocation', 'document']);
    }

    public function cancel(StatutoryInvoice $invoice, User $actor, string $reason): StatutoryInvoice
    {
        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            return $invoice;
        }

        $invoice->update([
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_by' => $actor->id,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        return $invoice->fresh() ?? $invoice;
    }

    private function linkCommerceOrder(CommerceOrder $order, StatutoryInvoice $invoice): void
    {
        if ($order->statutory_invoice_id === null) {
            $order->statutory_invoice_id = $invoice->id;
        }
        if ($order->status !== CommerceOrderStatus::Invoiced) {
            $order->status = CommerceOrderStatus::Invoiced;
        }
        $order->save();
    }

    private function generateDocumentSafely(StatutoryInvoice $invoice): void
    {
        try {
            $this->documents->generate($invoice);
        } catch (\Throwable) {
            // Number stays allocated. Document GET can regenerate.
        }
    }

    private function queueEinvoiceIfEligible(StatutoryInvoice $invoice): void
    {
        $decision = $this->einvoiceEligibility->evaluate($invoice);
        if (! $decision->eligible) {
            EInvoiceRecord::query()->updateOrCreate(
                ['invoice_id' => $invoice->id],
                [
                    'provider' => (string) config('statutory_invoices.einvoice.provider', 'none'),
                    'irn' => null,
                    'status' => EInvoiceRecordStatus::Skipped->value,
                    'response_payload' => ['skip_reason' => $decision->reason],
                ],
            );

            return;
        }

        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => (string) config('statutory_invoices.einvoice.provider', 'none'),
                'irn' => null,
                'status' => EInvoiceRecordStatus::Queued->value,
                'response_payload' => ['queue_reason' => $decision->reason],
            ],
        );
        $this->einvoiceOutbox->write($invoice);
    }

    private function deskSellerGstin(): string
    {
        return trim((string) config('statutory_invoices.gstin_scope'));
    }

    private function deskSellerName(): string
    {
        return trim((string) config('statutory_invoices.legal_name'));
    }

    /**
     * @return array{taxable_value: float, tax_total: float, invoice_value: float, cgst: ?float, sgst: ?float, igst: ?float}
     */
    private function totals(StatutoryInvoiceMintRequest $request): array
    {
        $taxable = 0.0;
        $tax = 0.0;
        $lineTotal = 0.0;
        $cgst = 0.0;
        $sgst = 0.0;
        $igst = 0.0;
        $hasCgst = false;
        $hasSgst = false;
        $hasIgst = false;

        foreach ($request->lines as $line) {
            $taxable += $line->taxableValue;
            $tax += $line->taxTotal;
            $lineTotal += $line->lineTotal;
            if ($line->cgst !== null) {
                $cgst += $line->cgst;
                $hasCgst = true;
            }
            if ($line->sgst !== null) {
                $sgst += $line->sgst;
                $hasSgst = true;
            }
            if ($line->igst !== null) {
                $igst += $line->igst;
                $hasIgst = true;
            }
        }

        return [
            'taxable_value' => round($taxable, 2),
            'tax_total' => round($tax, 2),
            'invoice_value' => round($lineTotal - $request->discount, 2),
            'cgst' => $hasCgst ? round($cgst, 2) : null,
            'sgst' => $hasSgst ? round($sgst, 2) : null,
            'igst' => $hasIgst ? round($igst, 2) : null,
        ];
    }

    private function assertLines(StatutoryInvoiceMintRequest $request): void
    {
        if ($request->lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A statutory invoice requires at least one line.',
            ]);
        }

        if ($request->sourceId === '') {
            throw ValidationException::withMessages([
                'source_id' => 'A statutory invoice requires a source id.',
            ]);
        }
    }

    private function assertInternalReceiptIsNotReused(StatutoryInvoiceMintRequest $request): void
    {
        if ($request->internalReceiptNumber === null || $request->internalReceiptNumber === '') {
            return;
        }

        $conflict = StatutoryInvoice::query()
            ->where('invoice_number', $request->internalReceiptNumber)
            ->first();
        if ($conflict !== null) {
            throw ValidationException::withMessages([
                'invoice_number' => 'The internal POS receipt number cannot be used as the statutory invoice number.',
            ]);
        }
    }

    private function assertNoFinanceJournal(StatutoryInvoice $invoice): void
    {
        if ($invoice->finance_journal_id !== null) {
            throw ValidationException::withMessages([
                'finance' => 'Statutory invoices must not post a finance journal in this foundation.',
            ]);
        }

        $count = FinanceJournal::query()
            ->where('source_type', 'statutory_invoice')
            ->where('source_id', $invoice->id)
            ->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'finance' => 'Statutory invoices must not post a finance journal in this foundation.',
            ]);
        }
    }
}
