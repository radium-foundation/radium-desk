<?php

namespace App\Reports\CaMonthly;

use App\Models\PaymentAllocation;
use App\Models\StatutoryInvoice;
use Illuminate\Support\Collection;

final class CaMonthlyReportPaymentEvidenceResolver
{
    /**
     * @param  array<int, float>|null  $allocationTotalsByInvoiceId
     */
    public function resolveForCancelledInvoice(
        StatutoryInvoice $invoice,
        ?array $allocationTotalsByInvoiceId = null,
    ): CaMonthlyReportCancelledPaymentEvidence {
        if ($this->nullableString($invoice->payment_reference) !== null) {
            return CaMonthlyReportCancelledPaymentEvidence::PaymentReference;
        }

        if ($this->nullableString($invoice->payment_method) !== null) {
            return CaMonthlyReportCancelledPaymentEvidence::PaymentMethod;
        }

        $allocated = $allocationTotalsByInvoiceId !== null
            ? ($allocationTotalsByInvoiceId[$invoice->id] ?? 0.0)
            : $this->allocatedTotalForInvoice($invoice->id);

        if ($allocated > 0) {
            return CaMonthlyReportCancelledPaymentEvidence::PaymentAllocation;
        }

        if ((float) $invoice->invoice_value > 0) {
            return CaMonthlyReportCancelledPaymentEvidence::InvoiceValueOnly;
        }

        return CaMonthlyReportCancelledPaymentEvidence::None;
    }

    /**
     * @param  iterable<int, StatutoryInvoice>  $invoices
     * @return array<int, float>
     */
    public function allocationTotalsForInvoices(iterable $invoices): array
    {
        $invoiceIds = [];
        foreach ($invoices as $invoice) {
            $invoiceIds[] = $invoice->id;
        }

        if ($invoiceIds === []) {
            return [];
        }

        return PaymentAllocation::query()
            ->selectRaw('statutory_invoice_id, SUM(amount) as allocated_total')
            ->whereIn('statutory_invoice_id', array_values(array_unique($invoiceIds)))
            ->groupBy('statutory_invoice_id')
            ->pluck('allocated_total', 'statutory_invoice_id')
            ->map(fn (mixed $total): float => round((float) $total, 2))
            ->all();
    }

    /**
     * @param  Collection<int, StatutoryInvoice>  $cancelledInvoices
     * @return array{
     *     included_via_payment_reference: int,
     *     included_via_payment_method: int,
     *     included_via_payment_allocation: int,
     *     ambiguous_invoice_value_only: int,
     *     excluded_no_payment_evidence: int,
     * }
     */
    public function summarizeCancelledInvoices(Collection $cancelledInvoices): array
    {
        $allocationTotals = $this->allocationTotalsForInvoices($cancelledInvoices);

        $summary = [
            'included_via_payment_reference' => 0,
            'included_via_payment_method' => 0,
            'included_via_payment_allocation' => 0,
            'ambiguous_invoice_value_only' => 0,
            'excluded_no_payment_evidence' => 0,
        ];

        foreach ($cancelledInvoices as $invoice) {
            $evidence = $this->resolveForCancelledInvoice($invoice, $allocationTotals);

            match ($evidence) {
                CaMonthlyReportCancelledPaymentEvidence::PaymentReference => $summary['included_via_payment_reference']++,
                CaMonthlyReportCancelledPaymentEvidence::PaymentMethod => $summary['included_via_payment_method']++,
                CaMonthlyReportCancelledPaymentEvidence::PaymentAllocation => $summary['included_via_payment_allocation']++,
                CaMonthlyReportCancelledPaymentEvidence::InvoiceValueOnly => $summary['ambiguous_invoice_value_only']++,
                CaMonthlyReportCancelledPaymentEvidence::None => $summary['excluded_no_payment_evidence']++,
            };
        }

        return $summary;
    }

    /**
     * @param  iterable<int, StatutoryInvoice>  $invoices
     * @return array<int, string>
     */
    public function allocationPaymentMethodsForInvoices(iterable $invoices): array
    {
        $invoiceIds = [];
        foreach ($invoices as $invoice) {
            $invoiceIds[] = $invoice->id;
        }

        if ($invoiceIds === []) {
            return [];
        }

        $rows = PaymentAllocation::query()
            ->select([
                'payment_allocations.statutory_invoice_id',
                'customer_payments.method',
            ])
            ->join('customer_payments', 'customer_payments.id', '=', 'payment_allocations.customer_payment_id')
            ->whereIn('payment_allocations.statutory_invoice_id', array_values(array_unique($invoiceIds)))
            ->where('payment_allocations.amount', '>', 0)
            ->orderBy('payment_allocations.id')
            ->get();

        $methods = [];
        foreach ($rows as $row) {
            $invoiceId = (int) $row->statutory_invoice_id;
            if (isset($methods[$invoiceId])) {
                continue;
            }

            $method = $this->nullableString($row->method);
            if ($method !== null) {
                $methods[$invoiceId] = $method;
            }
        }

        return $methods;
    }

    public function resolvePaymentModeDisplay(
        StatutoryInvoice $invoice,
        ?string $allocationPaymentMethod = null,
    ): string {
        $paymentMethod = $this->nullableString($invoice->payment_method);
        if ($paymentMethod !== null) {
            return $paymentMethod;
        }

        $allocationMethod = $this->nullableString($allocationPaymentMethod);
        if ($allocationMethod !== null) {
            return $allocationMethod;
        }

        return (string) ($this->nullableString($invoice->payment_reference) ?? '');
    }

    private function allocatedTotalForInvoice(int $invoiceId): float
    {
        return round((float) PaymentAllocation::query()
            ->where('statutory_invoice_id', $invoiceId)
            ->sum('amount'), 2);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
