<?php

namespace App\Reports\CaMonthly;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class CaMonthlyReportLineBuilder
{
    public function __construct(
        private readonly CaMonthlyReportPaymentEvidenceResolver $paymentEvidenceResolver,
    ) {}

    /**
     * @param  Collection<int, StatutoryInvoiceItem>  $items
     * @param  array<int, CaMonthlyReportOrderContext>  $orderContexts
     * @param  array<int, ?string>  $orderTypes
     * @param  array<int, string>  $allocationPaymentMethods
     * @return list<CaMonthlyReportLineRow>
     */
    public function buildRows(
        Collection $items,
        array $orderContexts,
        array $orderTypes,
        array $allocationPaymentMethods = [],
    ): array {
        $grouped = $items->groupBy('invoice_id');
        $rows = [];

        foreach ($grouped as $invoiceItems) {
            $invoice = $invoiceItems->first()?->invoice;
            if ($invoice === null) {
                continue;
            }

            $sorted = $invoiceItems->sortBy('line_no')->values();
            $lastLineNo = (int) ($sorted->last()?->line_no ?? 0);
            $firstLineNo = (int) ($sorted->first()?->line_no ?? 0);
            $orderContext = $orderContexts[$invoice->id] ?? new CaMonthlyReportOrderContext(null, null);
            $orderType = $orderTypes[$invoice->id] ?? '';
            $shippingAmount = round((float) ($invoice->shipping_amount ?? 0), 2);
            $paymentMode = $this->paymentEvidenceResolver->resolvePaymentModeDisplay(
                $invoice,
                $allocationPaymentMethods[$invoice->id] ?? null,
            );
            $documentType = $invoice->document_type->label();

            foreach ($sorted as $item) {
                $isFirstLine = (int) $item->line_no === $firstLineNo;
                $isLastLine = (int) $item->line_no === $lastLineNo;

                $rows[] = $this->buildRow(
                    $invoice,
                    $item,
                    $orderContext,
                    $orderType,
                    $paymentMode,
                    $documentType,
                    $isFirstLine,
                    $isLastLine,
                    $shippingAmount,
                );
            }
        }

        return $rows;
    }

    private function buildRow(
        StatutoryInvoice $invoice,
        StatutoryInvoiceItem $item,
        CaMonthlyReportOrderContext $orderContext,
        ?string $orderType,
        string $paymentMode,
        string $documentType,
        bool $isFirstLine,
        bool $isLastLine,
        float $invoiceShippingAmount,
    ): CaMonthlyReportLineRow {
        $taxable = round((float) $item->taxable_value, 2);
        $igst = round((float) ($item->igst ?? 0), 2);
        $cgst = round((float) ($item->cgst ?? 0), 2);
        $sgst = round((float) ($item->sgst ?? 0), 2);
        $shipping = $isFirstLine ? $invoiceShippingAmount : 0.0;
        $shortExcess = 0.0;

        if ($isLastLine) {
            $shortExcess = round((float) $invoice->rounding, 2);
        }

        $totalAmount = round((float) $item->line_total, 2);
        $calculatedTotal = round($taxable + $shipping + $igst + $cgst + $sgst + $shortExcess, 2);
        $reconciles = abs($totalAmount - $calculatedTotal) <= 0.01;

        $cells = [
            (string) ($invoice->branch?->name ?? $invoice->branch?->code ?? ''),
            (string) ($orderContext->orderDate ?? ''),
            (string) ($orderContext->orderId ?? ''),
            (string) ($orderType ?? ''),
            $this->formatDate($invoice->issued_at),
            (string) $invoice->invoice_number,
            (string) ($invoice->buyer_name ?? ''),
            (string) ($invoice->buyer_gstin ?? ''),
            (string) ($this->resolveState($invoice) ?? ''),
            (string) ($invoice->place_of_supply_state ?? ''),
            '',
            (string) $item->description,
            (string) $item->qty,
            (string) ($item->hsn_sac ?? ''),
            $this->money($taxable),
            $shipping !== 0.0 ? $this->money($shipping) : '',
            $this->zeroBlankMoney($item->igst),
            $this->zeroBlankMoney($item->cgst),
            $this->zeroBlankMoney($item->sgst),
            $shortExcess !== 0.0 ? $this->money($shortExcess) : '',
            $this->money($totalAmount),
            (string) ($invoice->eInvoiceRecord?->irn ?? ''),
            (string) ($invoice->eInvoiceRecord?->ack_no ?? ''),
            $invoice->status->label(),
            $paymentMode,
            $this->money($taxable),
            $documentType,
        ];

        return new CaMonthlyReportLineRow(
            cells: $cells,
            taxableAmount: $taxable,
            shippingAmount: $shipping,
            igst: $igst,
            cgst: $cgst,
            sgst: $sgst,
            shortExcess: $shortExcess,
            totalAmount: $totalAmount,
            lineDiscount: round((float) $item->discount, 2),
            reconciles: $reconciles,
        );
    }

    private function resolveState(StatutoryInvoice $invoice): ?string
    {
        $structured = StatutoryBillingStructured::fromStored($invoice->billing_address_structured);

        return StatutoryBillingStructured::nullable($structured['state'] ?? null);
    }

    private function formatDate(mixed $value): string
    {
        if (! $value instanceof \DateTimeInterface) {
            return '';
        }

        return Carbon::instance($value)
            ->timezone((string) config('app.timezone'))
            ->format('Y-m-d');
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function zeroBlankMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $amount = round((float) $value, 2);
        if ($amount === 0.0) {
            return '';
        }

        return $this->money($amount);
    }
}
