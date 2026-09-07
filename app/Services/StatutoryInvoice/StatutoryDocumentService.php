<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Models\HardwareFulfilment;
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
            ];
        }

        $profile = $this->seller->tryForLocation($this->seller->locationForInvoice($invoice));
        $fulfilment = HardwareFulfilment::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
        $serials = $this->serialsForInvoice($fulfilment);

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
        );
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
     * @return list<string>
     */
    private function serialsForInvoice(?HardwareFulfilment $fulfilment): array
    {
        if ($fulfilment === null) {
            return [];
        }

        $locked = $fulfilment->metadata['invoice_serials'] ?? null;
        if (is_array($locked) && $locked !== []) {
            return array_values(array_map(static fn (mixed $serial): string => (string) $serial, $locked));
        }

        return $this->hardwareWorkflow->allocatedSerialNumbers($fulfilment);
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

    private function formatMoney(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
