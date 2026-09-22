<?php

namespace App\Reports\CaMonthly;

use App\Models\CommerceOrder;
use App\Models\HardwareFulfilmentPaymentEvidence;
use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Models\StatutoryInvoice;
use Illuminate\Support\Collection;

final class CaMonthlyReportPaymentEvidenceResolver
{
    /**
     * Payment providers are not payment instruments and must not be shown as Payment Mode.
     *
     * @var list<string>
     */
    private const PAYMENT_PROVIDER_ALIASES = [
        'cashfree',
        'payumoney',
        'payu',
        'razorpay',
    ];

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

            $method = $this->normalizePaymentMode($row->method);
            if ($method !== null) {
                $methods[$invoiceId] = $method;
            }
        }

        return $methods;
    }

    /**
     * @param  iterable<int, StatutoryInvoice>  $invoices
     * @return array<int, string>
     */
    public function hardwarePaymentMethodsForInvoices(iterable $invoices): array
    {
        $sourceIds = [];
        $commerceOrderIds = [];
        foreach ($invoices as $invoice) {
            $sourceId = $this->nullableString($invoice->source_order_id)
                ?? $this->nullableString($invoice->source_id);
            if ($sourceId !== null) {
                $sourceIds[strtoupper($sourceId)] = true;
            }
        }

        if ($sourceIds === []) {
            return [];
        }

        $rows = HardwareFulfilmentPaymentEvidence::query()
            ->where('verified', true)
            ->where(function ($query) use ($sourceIds): void {
                $query->whereIn('source_id', array_keys($sourceIds));
            })
            ->orderBy('id')
            ->get();

        $bySource = [];
        $byCommerce = [];
        foreach ($rows as $row) {
            $method = $this->normalizePaymentMode($row->payment_method);
            if ($method === null) {
                continue;
            }

            $sourceId = strtoupper((string) $row->source_id);
            if ($sourceId !== '' && ! isset($bySource[$sourceId])) {
                $bySource[$sourceId] = $method;
            }

            if ($row->commerce_order_id !== null && ! isset($byCommerce[(int) $row->commerce_order_id])) {
                $byCommerce[(int) $row->commerce_order_id] = $method;
            }
        }

        $methods = [];
        foreach ($invoices as $invoice) {
            $sourceId = strtoupper((string) ($this->nullableString($invoice->source_order_id)
                ?? $this->nullableString($invoice->source_id)
                ?? ''));
            if ($sourceId !== '' && isset($bySource[$sourceId])) {
                $methods[$invoice->id] = $bySource[$sourceId];
            }
        }

        return $methods;
    }

    /**
     * @param  iterable<int, StatutoryInvoice>  $invoices
     * @return array<int, string>
     */
    public function supportOrderPaymentMethodsForInvoices(iterable $invoices): array
    {
        $supportOrderIds = [];
        foreach ($invoices as $invoice) {
            if ($invoice->support_order_id !== null) {
                $supportOrderIds[] = (int) $invoice->support_order_id;
            }
        }

        if ($supportOrderIds === []) {
            return [];
        }

        $orders = Order::query()
            ->whereIn('id', array_values(array_unique($supportOrderIds)))
            ->get()
            ->keyBy('id');

        $methods = [];
        foreach ($invoices as $invoice) {
            if ($invoice->support_order_id === null) {
                continue;
            }

            $order = $orders->get((int) $invoice->support_order_id);
            if ($order === null) {
                continue;
            }

            $method = $this->normalizePaymentMode($order->payment_method);
            if ($method !== null) {
                $methods[$invoice->id] = $method;
            }
        }

        return $methods;
    }

    public function resolvePaymentModeDisplay(
        StatutoryInvoice $invoice,
        ?string $allocationPaymentMethod = null,
        ?CommerceOrder $commerceOrder = null,
        ?string $hardwarePaymentMethod = null,
        ?string $supportOrderPaymentMethod = null,
    ): string {
        foreach ([
            $hardwarePaymentMethod,
            $supportOrderPaymentMethod,
            $this->normalizePaymentMode($commerceOrder?->payment_method),
            $allocationPaymentMethod,
            $this->normalizePaymentMode($invoice->payment_method),
        ] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function normalizePaymentMode(mixed $value): ?string
    {
        $trimmed = $this->nullableString($value);
        if ($trimmed === null) {
            return null;
        }

        $normalized = strtolower(str_replace(['_', '-'], ' ', $trimmed));
        foreach (self::PAYMENT_PROVIDER_ALIASES as $provider) {
            if ($normalized === $provider || str_contains($normalized, $provider)) {
                return null;
            }
        }

        return $this->formatPaymentModeLabel($trimmed);
    }

    private function formatPaymentModeLabel(string $value): string
    {
        $normalized = strtolower(str_replace(['_', '-'], ' ', trim($value)));

        return match ($normalized) {
            'upi' => 'UPI',
            'card', 'credit card', 'debit card' => 'Card',
            'netbanking', 'net banking', 'nb' => 'Net Banking',
            'wallet' => 'Wallet',
            'bank transfer', 'bank_transfer', 'neft', 'imps', 'rtgs' => 'Bank Transfer',
            'cod', 'cash on delivery' => 'COD',
            'cash' => 'Cash',
            default => ucwords($normalized),
        };
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
