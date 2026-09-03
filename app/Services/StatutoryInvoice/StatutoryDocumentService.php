<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceDocumentStatus;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceDocument;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StatutoryDocumentService
{
    public function __construct(
        private readonly SimplePdfRenderer $renderer,
    ) {}

    public function generate(StatutoryInvoice $invoice): StatutoryInvoiceDocument
    {
        $invoice->loadMissing('items');
        $document = StatutoryInvoiceDocument::query()->firstOrNew(['invoice_id' => $invoice->id]);
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

    private function payloadFromInvoice(StatutoryInvoice $invoice): StatutoryInvoicePdfPayload
    {
        $lines = [];
        foreach ($invoice->items as $line) {
            $lines[] = [
                'description' => (string) $line->description,
                'hsnSac' => (string) $line->hsn_sac,
                'qty' => (int) $line->qty,
                'taxableValue' => (string) $line->taxable_value,
                'cgst' => (string) ($line->cgst ?? '0'),
                'sgst' => (string) ($line->sgst ?? '0'),
                'igst' => (string) ($line->igst ?? '0'),
                'lineTotal' => (string) $line->line_total,
            ];
        }

        return new StatutoryInvoicePdfPayload(
            invoiceNumber: (string) $invoice->invoice_number,
            issuedAt: optional($invoice->issued_at)?->toDateTimeString() ?? '',
            sellerLegalName: (string) ($invoice->seller_name ?: $this->configString('legal_name') ?: 'unset'),
            sellerGstin: (string) ($invoice->seller_gstin ?: $this->configString('gstin_scope') ?: 'unset'),
            sellerAddress: $this->configString('seller_address') ?? 'unset',
            sellerState: $this->configString('seller_state') ?? 'unset',
            buyerName: (string) ($invoice->buyer_name ?: 'Customer'),
            buyerGstin: $invoice->buyer_gstin,
            billingAddress: $invoice->billing_address,
            placeOfSupply: (string) ($invoice->place_of_supply_state ?: 'unset'),
            lines: $lines,
            taxableValue: (string) $invoice->taxable_value,
            cgst: (string) ($invoice->cgst ?? '0'),
            sgst: (string) ($invoice->sgst ?? '0'),
            igst: (string) ($invoice->igst ?? '0'),
            invoiceValue: (string) $invoice->invoice_value,
        );
    }

    private function configString(string $key): ?string
    {
        $value = config('statutory_invoices.'.$key);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
