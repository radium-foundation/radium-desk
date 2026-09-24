<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\InventorySaleStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\PosSaleService;
use Illuminate\Validation\ValidationException;

final class StatutoryInvoiceLinkedInventoryReversalService
{
    public function __construct(
        private readonly PosSaleService $posSales,
    ) {}

    /**
     * @return array{status: string, message: string, sale_id?: int, serials?: list<string>}
     */
    public function reverseIfRequired(StatutoryInvoice $invoice, User $actor, string $reason): array
    {
        if ($invoice->channel !== StatutoryInvoiceChannel::DeskPos || $invoice->inventory_sale_id === null) {
            return [
                'status' => 'not_applicable',
                'message' => 'No linked POS inventory sale requires reversal for this invoice.',
            ];
        }

        $sale = $invoice->inventorySale;
        if ($sale === null) {
            return [
                'status' => 'not_applicable',
                'message' => 'Linked POS sale was not found.',
            ];
        }

        if (in_array($sale->status, [InventorySaleStatus::Cancelled, InventorySaleStatus::Returned], true)) {
            return [
                'status' => 'already_reversed',
                'message' => 'Linked POS sale inventory was already reversed.',
                'sale_id' => $sale->id,
            ];
        }

        if ($sale->status !== InventorySaleStatus::Completed) {
            throw ValidationException::withMessages([
                'inventory' => 'Linked POS sale is not in a reversible completed state.',
            ]);
        }

        $serials = $this->serialsOnSale($sale);
        $this->posSales->cancelSale($sale->fresh(), $actor, $reason);

        return [
            'status' => 'reversed',
            'message' => 'Linked POS sale inventory and finance journal were reversed.',
            'sale_id' => $sale->id,
            'serials' => $serials,
        ];
    }

    /**
     * @return list<string>
     */
    private function serialsOnSale(InventorySale $sale): array
    {
        $sale->loadMissing('serials.serial');

        return $sale->serials
            ->map(fn ($assignment) => (string) ($assignment->serial?->serial_number ?? ''))
            ->filter(fn (string $serial): bool => $serial !== '')
            ->sort()
            ->values()
            ->all();
    }
}
