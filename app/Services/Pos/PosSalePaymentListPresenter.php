<?php

namespace App\Services\Pos;

use App\Enums\StatutoryInvoicePaymentStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Services\Pos\Data\PosSalePaymentListStatus;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentReadService;
use App\Support\Inventory\PosSalePaymentState;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Collection;

final class PosSalePaymentListPresenter
{
    public function __construct(
        private readonly StatutoryInvoicePaymentReadService $payments,
    ) {}

    /**
     * @param  Paginator<int, InventorySale>|Collection<int, InventorySale>  $sales
     * @return array<int, PosSalePaymentListStatus>
     */
    public function forSales(Paginator|Collection $sales): array
    {
        $collection = $sales instanceof Paginator ? $sales->getCollection() : $sales;

        $invoiceIds = $collection
            ->pluck('statutory_invoice_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $allocatedTotals = $this->payments->allocatedTotalsForInvoices($invoiceIds);

        $statuses = [];
        foreach ($collection as $sale) {
            $statuses[(int) $sale->id] = $this->forSale($sale, $allocatedTotals);
        }

        return $statuses;
    }

    /**
     * @param  array<int, float>  $allocatedTotals
     */
    private function forSale(InventorySale $sale, array $allocatedTotals): PosSalePaymentListStatus
    {
        $invoice = $sale->statutoryInvoice;
        if ($invoice === null) {
            if (PosSalePaymentState::displaysAsPaymentPending($sale)) {
                return $this->fromPaymentStatus(StatutoryInvoicePaymentStatus::Unpaid);
            }

            return PosSalePaymentListStatus::unavailable(
                'No GST invoice issued yet. Finance payment status is tracked on the statutory invoice after issuance.',
            );
        }

        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            return new PosSalePaymentListStatus(
                label: 'Invoice cancelled',
                badgeClass: 'bg-secondary',
                accessibleTitle: 'The statutory GST invoice for this sale was cancelled. Payment status may not reflect checkout tender.',
            );
        }

        if (! $this->payments->usesAllocationBackedPaymentEvidence($invoice)) {
            return PosSalePaymentListStatus::unavailable(
                'Payment status for this invoice channel is not shown on the POS sales list.',
            );
        }

        $invoiceValue = round((float) $invoice->invoice_value, 2);
        $received = $allocatedTotals[(int) $invoice->id] ?? 0.0;
        $status = $this->payments->allocationBackedPaymentStatus($invoiceValue, $received);

        return $this->fromPaymentStatus($status);
    }

    private function fromPaymentStatus(StatutoryInvoicePaymentStatus $status): PosSalePaymentListStatus
    {
        return match ($status) {
            StatutoryInvoicePaymentStatus::Paid => new PosSalePaymentListStatus(
                label: $status->label(),
                badgeClass: 'bg-success',
            ),
            StatutoryInvoicePaymentStatus::PartiallyPaid => new PosSalePaymentListStatus(
                label: $status->label(),
                badgeClass: 'bg-info text-dark',
            ),
            StatutoryInvoicePaymentStatus::Unpaid => new PosSalePaymentListStatus(
                label: $status->label(),
                badgeClass: 'bg-warning text-dark',
            ),
            StatutoryInvoicePaymentStatus::Refunded,
            StatutoryInvoicePaymentStatus::PartiallyRefunded => new PosSalePaymentListStatus(
                label: $status->label(),
                badgeClass: 'bg-secondary',
                accessibleTitle: 'Refund-related payment status from Finance records.',
            ),
        };
    }
}
