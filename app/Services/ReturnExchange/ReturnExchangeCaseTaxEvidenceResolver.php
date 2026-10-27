<?php

namespace App\Services\ReturnExchange;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Models\ReturnExchangeCase;
use App\Models\StatutoryInvoice;
use App\Services\ReturnExchange\Data\ReturnExchangeCaseTaxEvidence;
use App\Services\ReturnExchange\Data\ReturnExchangeCaseTaxEvidenceInvoice;
use Illuminate\Support\Collection;

final class ReturnExchangeCaseTaxEvidenceResolver
{
    public function forCase(ReturnExchangeCase $case): ReturnExchangeCaseTaxEvidence
    {
        $case->loadMissing(['inventorySale', 'order', 'hardwareFulfilment']);

        return match ($case->source_kind) {
            ReturnExchangeCaseSourceKind::PosSale => $this->fromPosSale($case),
            ReturnExchangeCaseSourceKind::EcommerceFulfilment => $this->fromFulfilment($case),
            ReturnExchangeCaseSourceKind::EcommerceOrder,
            ReturnExchangeCaseSourceKind::ServiceOrder => $this->fromSupportOrder($case),
        };
    }

    private function fromPosSale(ReturnExchangeCase $case): ReturnExchangeCaseTaxEvidence
    {
        if ($case->inventory_sale_id === null) {
            return $this->notLinked($case, 'POS sale is not linked on this case.');
        }

        $sale = $case->inventorySale;
        if ($sale === null) {
            return $this->notLinked($case, 'POS sale record was not found.');
        }

        $invoices = collect();
        if ($sale->statutory_invoice_id !== null) {
            $direct = StatutoryInvoice::query()->find($sale->statutory_invoice_id);
            if ($direct !== null) {
                $invoices->push($direct);
            }
        }

        $invoices = $this->uniqueInvoices(
            $invoices->merge(
                StatutoryInvoice::query()
                    ->where('inventory_sale_id', $sale->id)
                    ->get(),
            ),
        );

        return $this->package($case, $invoices, 'POS sale #'.$sale->id);
    }

    private function fromSupportOrder(ReturnExchangeCase $case): ReturnExchangeCaseTaxEvidence
    {
        if ($case->order_id === null) {
            return $this->notLinked($case, 'Desk order is not linked on this case.');
        }

        $invoices = $this->uniqueInvoices(
            StatutoryInvoice::query()
                ->where('support_order_id', $case->order_id)
                ->get(),
        );

        return $this->package($case, $invoices, 'Desk order #'.$case->order_id);
    }

    private function fromFulfilment(ReturnExchangeCase $case): ReturnExchangeCaseTaxEvidence
    {
        if ($case->hardware_fulfilment_id === null) {
            return $this->notLinked($case, 'Hardware fulfilment is not linked on this case.');
        }

        $fulfilment = $case->hardwareFulfilment;
        if ($fulfilment === null) {
            return $this->notLinked($case, 'Hardware fulfilment record was not found.');
        }

        $invoices = collect();
        if ($fulfilment->statutory_invoice_id !== null) {
            $invoice = StatutoryInvoice::query()->find($fulfilment->statutory_invoice_id);
            if ($invoice !== null) {
                $invoices->push($invoice);
            }
        }

        if (
            $case->order_id !== null
            && $fulfilment->support_order_id !== null
            && (int) $case->order_id === (int) $fulfilment->support_order_id
        ) {
            $invoices = $this->uniqueInvoices(
                $invoices->merge(
                    StatutoryInvoice::query()
                        ->where('support_order_id', $case->order_id)
                        ->get(),
                ),
            );
        }

        return $this->package($case, $invoices, 'Fulfilment #'.$fulfilment->id);
    }

    /**
     * @param  Collection<int, StatutoryInvoice>  $invoices
     */
    private function package(ReturnExchangeCase $case, Collection $invoices, string $linkPrefix): ReturnExchangeCaseTaxEvidence
    {
        if ($invoices->isEmpty()) {
            return $this->notLinked($case, 'No statutory invoice is linked through Desk for '.$linkPrefix.'.');
        }

        if ($invoices->count() > 1) {
            return new ReturnExchangeCaseTaxEvidence(
                sourceKindLabel: $case->source_kind->label(),
                linkState: 'ambiguous',
                linkMessage: 'Multiple statutory invoices are linked for '.$linkPrefix.'. Finance must reconcile manually before any tax action.',
                invoices: $invoices
                    ->map(fn (StatutoryInvoice $invoice): ReturnExchangeCaseTaxEvidenceInvoice => $this->mapInvoice($invoice, $linkPrefix))
                    ->values()
                    ->all(),
            );
        }

        return new ReturnExchangeCaseTaxEvidence(
            sourceKindLabel: $case->source_kind->label(),
            linkState: 'linked',
            linkMessage: 'Statutory evidence linked via '.$linkPrefix.'.',
            invoices: [$this->mapInvoice($invoices->first(), $linkPrefix)],
        );
    }

    private function mapInvoice(StatutoryInvoice $invoice, string $linkLabel): ReturnExchangeCaseTaxEvidenceInvoice
    {
        $invoice->loadMissing(['eInvoiceRecord', 'cancellation', 'creditNotes']);

        $irnState = 'unknown';
        $record = $invoice->eInvoiceRecord;
        if ($record === null) {
            $irnState = 'absent';
        } elseif ($record->hasIssuedIrn()) {
            $irnState = 'present';
        } else {
            $irnState = $record->status !== null && $record->status !== ''
                ? 'status:'.$record->status
                : 'absent';
        }

        $cancellationSummary = null;
        if ($invoice->cancellation !== null) {
            $summary = $invoice->cancellation->result_summary;
            $cancellationSummary = is_array($summary)
                ? json_encode($summary, JSON_UNESCAPED_UNICODE)
                : (string) ($invoice->cancellation->completed_at?->format('Y-m-d H:i') ?? 'Recorded');
        } elseif ($invoice->cancelled_at !== null) {
            $cancellationSummary = 'Invoice cancelled '.$invoice->cancelled_at->format('d M Y H:i');
        }

        $creditNotes = $invoice->creditNotes
            ->map(fn (StatutoryInvoice $cn): string => $cn->invoice_number.' ('.$cn->status->label().')')
            ->values()
            ->all();

        return new ReturnExchangeCaseTaxEvidenceInvoice(
            linkLabel: $linkLabel,
            invoiceNumber: (string) $invoice->invoice_number,
            documentTypeLabel: $invoice->document_type->label(),
            statusLabel: $invoice->status->label(),
            issuedAt: $invoice->issued_at?->format('d M Y H:i'),
            irnState: $irnState,
            cancellationSummary: $cancellationSummary,
            creditNoteSummaries: $creditNotes,
            ewayState: 'not_linked',
        );
    }

    /**
     * @param  Collection<int, StatutoryInvoice>  $invoices
     * @return Collection<int, StatutoryInvoice>
     */
    private function uniqueInvoices(Collection $invoices): Collection
    {
        return $invoices
            ->filter()
            ->unique(fn (StatutoryInvoice $invoice): int => (int) $invoice->id)
            ->values();
    }

    private function notLinked(ReturnExchangeCase $case, string $message): ReturnExchangeCaseTaxEvidence
    {
        return new ReturnExchangeCaseTaxEvidence(
            sourceKindLabel: $case->source_kind->label(),
            linkState: 'not_linked',
            linkMessage: $message,
            invoices: [],
        );
    }
}
