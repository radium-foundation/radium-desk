<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StatutoryDocumentService
{
    public function __construct(
        private readonly SimplePdfRenderer $renderer,
        private readonly StatutorySellerIdentity $seller,
        private readonly HardwareFulfilmentWorkflowService $hardwareWorkflow,
    ) {}

    public function generate(StatutoryInvoice $invoice): StatutoryInvoiceDocument
    {
        $invoice->loadMissing(['items', 'eInvoiceRecord']);
        $document = StatutoryInvoiceDocument::query()->firstOrNew(['invoice_id' => $invoice->id]);
        if ($this->hasImmutableGeneratedDocument($document)) {
            return $document;
        }

        return $this->writeGeneratedDocument($invoice, $document);
    }

    /**
     * Rewrite the statutory PDF only after a newly issued IRN. Does not
     * bulk-regenerate historical invoices.
     */
    public function finalizeAfterIrn(StatutoryInvoice $invoice): ?StatutoryInvoiceDocument
    {
        $invoice->loadMissing(['items', 'eInvoiceRecord']);
        if ($this->issuedIrn($invoice) === null) {
            return $invoice->document;
        }

        $document = StatutoryInvoiceDocument::query()->firstOrNew(['invoice_id' => $invoice->id]);

        return $this->writeGeneratedDocument($invoice, $document);
    }

    /**
     * Presentation-only rewrite from stored invoice/IRN/serials. Does not mint,
     * recompute tax, or change invoice identity.
     */
    public function regeneratePresentation(StatutoryInvoice $invoice): StatutoryInvoiceDocument
    {
        $invoice->loadMissing(['items', 'eInvoiceRecord']);
        $document = StatutoryInvoiceDocument::query()->firstOrNew(['invoice_id' => $invoice->id]);

        return $this->writeGeneratedDocument($invoice, $document);
    }

    /**
     * Production serial-correction callers still invoke this name. Same
     * presentation rewrite as regeneratePresentation(); does not change
     * invoice identity, tax, or serial allocation.
     */
    public function regenerateForHardwareSerialCorrection(StatutoryInvoice $invoice): StatutoryInvoiceDocument
    {
        return $this->regeneratePresentation($invoice);
    }

    private function writeGeneratedDocument(
        StatutoryInvoice $invoice,
        StatutoryInvoiceDocument $document,
    ): StatutoryInvoiceDocument {
        $document->attempts = (int) $document->attempts + 1;

        try {
            $binary = $this->renderer->render($this->payloadFromInvoice($invoice));
            $path = 'statutory-invoices/'.$invoice->id.'.pdf';
            Storage::disk('local')->put($path, $binary);

            $document->fill([
                'status' => StatutoryInvoiceDocumentStatus::Generated,
                'disk' => 'local',
                'path' => $path,
                'content_type' => 'application/pdf',
                'checksum' => hash('sha256', $binary),
                'last_error' => null,
                'generated_at' => now(),
            ]);
            $document->save();

            return $document;
        } catch (Throwable $exception) {
            $document->fill([
                'status' => StatutoryInvoiceDocumentStatus::Failed,
                'last_error' => $exception->getMessage(),
            ]);
            $document->save();

            throw $exception;
        }
    }

    public function binary(StatutoryInvoiceDocument $document): string
    {
        $disk = $document->disk ?: 'local';
        $path = (string) $document->path;
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException('The statutory PDF is not available.');
        }

        return (string) Storage::disk($disk)->get($path);
    }

    private function hasImmutableGeneratedDocument(StatutoryInvoiceDocument $document): bool
    {
        if (! $document->exists || $document->status !== StatutoryInvoiceDocumentStatus::Generated) {
            return false;
        }

        $path = (string) $document->path;
        $disk = $document->disk ?: 'local';

        return $path !== '' && Storage::disk($disk)->exists($path);
    }

    private function payloadFromInvoice(StatutoryInvoice $invoice): StatutoryInvoicePdfPayload
    {
        $lines = [];
        foreach ($invoice->items as $line) {
            $lines[] = [
                'description' => (string) $line->description,
                'hsnSac' => (string) ($line->hsn_sac ?: ''),
                'qty' => (int) $line->qty,
                'unitPrice' => $this->formatMoney($line->unit_price),
                'taxableValue' => $this->formatMoney($line->taxable_value),
                'gstPercentage' => $this->formatRate($line->gst_percentage),
                'cgst' => $this->formatTaxComponent($line->cgst),
                'sgst' => $this->formatTaxComponent($line->sgst),
                'igst' => $this->formatTaxComponent($line->igst),
                'taxTotal' => $this->formatMoney($line->tax_total),
                'lineTotal' => $this->formatMoney($line->line_total),
                'uqc' => $this->nullableString($line->getAttribute('uqc')),
            ];
        }

        $profile = $this->seller->tryForLocation($this->seller->locationForInvoice($invoice));
        $fulfilment = HardwareFulfilment::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
        $serials = $this->serialsForInvoice($invoice, $fulfilment);
        $commerce = $this->commercePresentation($invoice);

        return new StatutoryInvoicePdfPayload(
            invoiceNumber: (string) $invoice->invoice_number,
            issuedAt: optional($invoice->issued_at)?->toDateTimeString() ?? '',
            sellerLegalName: (string) ($invoice->seller_name ?: $this->seller->legalName() ?: ''),
            sellerGstin: (string) ($invoice->seller_gstin ?: $profile?->gstin ?: ''),
            sellerAddress: $profile?->address ?? '',
            sellerState: $profile?->state ?? '',
            buyerName: (string) ($invoice->buyer_name ?: 'Customer'),
            buyerGstin: $invoice->buyer_gstin,
            billingAddress: $invoice->billing_address,
            placeOfSupply: (string) ($invoice->place_of_supply_state ?: ''),
            lines: $lines,
            taxableValue: $this->formatMoney($invoice->taxable_value),
            gstRate: $this->headerGstRate($invoice),
            taxTotal: $this->formatMoney($invoice->tax_total),
            cgst: $this->formatTaxComponent($invoice->cgst),
            sgst: $this->formatTaxComponent($invoice->sgst),
            igst: $this->formatTaxComponent($invoice->igst),
            invoiceValue: $this->formatMoney($invoice->invoice_value),
            serialNumbers: $serials,
            sourceId: $invoice->source_id,
            fulfilmentId: $fulfilment?->id,
            irn: $this->issuedIrn($invoice),
            ackNo: $this->issuedAckNo($invoice),
            ackDate: $this->issuedAckDate($invoice),
            shippingAddress: $commerce['shipping'],
            paymentMethod: $this->paymentMethodFor($invoice),
            paymentStatus: $this->paymentMethodFor($invoice) !== null ? 'Paid' : null,
            signedQr: $this->issuedSignedQr($invoice),
            sellerEmail: $this->nullableString(config('statutory_invoices.contact_email')),
            sellerPhone: $this->nullableString(config('statutory_invoices.contact_phone')),
            buyerPhone: $this->nullableString($invoice->buyer_phone),
            buyerEmail: $commerce['email'],
            discount: $this->optionalMoney($invoice->discount),
            rounding: $this->optionalMoney($invoice->rounding),
            paymentReference: $this->nullableString($invoice->payment_reference),
            orderId: $this->nullableString($invoice->source_order_id) ?? $this->nullableString($invoice->source_id),
        );
    }

    /**
     * @return array{shipping: ?string, email: ?string}
     */
    private function commercePresentation(StatutoryInvoice $invoice): array
    {
        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            return ['shipping' => null, 'email' => null];
        }

        $order = CommerceOrder::query()
            ->where('channel', $invoice->channel)
            ->where('source_id', $invoice->source_id)
            ->first(['shipping_address', 'customer_email']);

        return [
            'shipping' => $this->nullableString($order?->shipping_address),
            'email' => $this->nullableString($order?->customer_email),
        ];
    }

    private function paymentMethodFor(StatutoryInvoice $invoice): ?string
    {
        $method = trim((string) ($invoice->payment_method ?? ''));

        return $method !== '' ? $method : null;
    }

    private function issuedSignedQr(StatutoryInvoice $invoice): ?string
    {
        if ($this->issuedIrn($invoice) === null) {
            return null;
        }

        $qr = trim((string) ($invoice->eInvoiceRecord?->signed_qr ?? ''));

        return $qr !== '' ? $qr : null;
    }

    private function issuedIrn(StatutoryInvoice $invoice): ?string
    {
        $record = $invoice->eInvoiceRecord;
        if ($record === null || $record->status !== EInvoiceRecordStatus::Submitted->value) {
            return null;
        }

        $irn = trim((string) $record->irn);

        return $irn !== '' ? $irn : null;
    }

    private function issuedAckNo(StatutoryInvoice $invoice): ?string
    {
        if ($this->issuedIrn($invoice) === null) {
            return null;
        }

        $ack = trim((string) ($invoice->eInvoiceRecord?->ack_no ?? ''));

        return $ack !== '' ? $ack : null;
    }

    private function issuedAckDate(StatutoryInvoice $invoice): ?string
    {
        if ($this->issuedIrn($invoice) === null) {
            return null;
        }

        $date = $invoice->eInvoiceRecord?->ack_date;
        if ($date === null) {
            return null;
        }

        return $date->timezone((string) config('app.timezone'))->format('d M Y H:i');
    }

    /**
     * Presentation-only serial list. Does not rewrite stored invoice lines.
     *
     * @return list<string>
     */
    private function serialsForInvoice(StatutoryInvoice $invoice, ?HardwareFulfilment $fulfilment): array
    {
        if ($fulfilment !== null) {
            $locked = $fulfilment->metadata['invoice_serials'] ?? null;
            if (is_array($locked) && $locked !== []) {
                return array_values(array_map(static fn (mixed $serial): string => (string) $serial, $locked));
            }

            return $this->hardwareWorkflow->allocatedSerialNumbers($fulfilment);
        }

        if ($invoice->inventory_sale_id === null) {
            return [];
        }

        $sale = InventorySale::query()->with(['serials.serial'])->find($invoice->inventory_sale_id);
        if ($sale === null) {
            return [];
        }

        $out = [];
        foreach ($sale->serials as $assignment) {
            $value = trim((string) ($assignment->serial?->serial_number ?? ''));
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function headerGstRate(StatutoryInvoice $invoice): string
    {
        $rates = $invoice->items
            ->pluck('gst_percentage')
            ->filter(static fn ($rate): bool => $rate !== null)
            ->map(static fn ($rate): string => number_format((float) $rate, 2, '.', ''))
            ->unique()
            ->values();

        if ($rates->count() === 1) {
            return $rates[0].'%';
        }

        return $rates->isEmpty() ? 'not recorded' : 'see lines';
    }

    private function formatTaxComponent(mixed $value): string
    {
        if ($value === null) {
            return 'not recorded';
        }

        return $this->formatMoney($value);
    }

    private function formatRate(mixed $value): string
    {
        if ($value === null) {
            return 'not recorded';
        }

        return number_format((float) $value, 2, '.', '').'%';
    }

    private function optionalMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = (float) $value;
        if (abs($amount) < 0.005) {
            return null;
        }

        return $this->formatMoney($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function formatMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
