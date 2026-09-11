<?php

namespace App\Services\Pos;

use App\Enums\PosCustomerType;
use App\Models\InventoryBranch;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\Inventory\PosSaleService;
use App\Services\Pos\Data\PosWalkInCompletionResult;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PosWalkInCompletionService
{
    public function __construct(
        private readonly PosSaleService $sales,
        private readonly StatutoryInvoiceService $statutoryInvoices,
        private readonly PosFinancePartyCustomerService $partyCustomers,
        private readonly WalkInPlaceOfSupplyResolver $placeOfSupply,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  list<array{
     *     product_id: int,
     *     variant_id?: int|null,
     *     qty: int,
     *     serials?: list<string>|string|null,
     *     unit_price?: float|string|null,
     *     discount?: float|string|null
     * }>  $lines
     */
    public function complete(
        InventoryBranch $branch,
        array $input,
        array $lines,
        string $paymentMethod,
        User $actor,
        float $headerDiscount = 0,
        ?string $paymentReference = null,
        ?string $notes = null,
        ?InventoryReservation $reservation = null,
        ?string $idempotencyKey = null,
    ): PosWalkInCompletionResult {
        $customerType = PosCustomerType::tryFrom((string) ($input['customer_type'] ?? ''))
            ?? PosCustomerType::B2c;

        $resolved = $this->partyCustomers->resolveForSale($branch, $input, $customerType);
        $pos = $this->placeOfSupply->resolveForWalkInSale(
            $branch,
            $input['place_of_supply_state'] ?? null,
            $input['delivery_state'] ?? null,
        );

        $statutory = array_merge($resolved['statutory'], [
            'place_of_supply_state' => $pos->state,
            'place_of_supply_source' => $pos->source,
        ]);

        $sale = $this->sales->completeSale(
            branch: $branch,
            customer: $resolved['customer'],
            lines: $lines,
            paymentMethod: $paymentMethod,
            actor: $actor,
            headerDiscount: $headerDiscount,
            paymentReference: $paymentReference,
            notes: $notes,
            reservation: $reservation,
            idempotencyKey: $idempotencyKey,
            statutory: $statutory,
        );

        if ($sale->statutory_invoice_id !== null) {
            return new PosWalkInCompletionResult(
                $sale->fresh(['lines.product', 'serials.serial', 'customer', 'branch', 'statutoryInvoice.document', 'statutoryInvoice.eInvoiceRecord']) ?? $sale,
                $sale->statutoryInvoice,
            );
        }

        if (! (bool) config('statutory_invoices.walk_in_auto_issue_statutory', false)) {
            return new PosWalkInCompletionResult($sale, null);
        }

        try {
            $invoice = $this->statutoryInvoices->issueFromPosSale($sale, $actor);
            $sale = $sale->fresh(['lines.product', 'serials.serial', 'customer', 'branch', 'statutoryInvoice.document', 'statutoryInvoice.eInvoiceRecord']) ?? $sale;

            return new PosWalkInCompletionResult($sale, $invoice);
        } catch (ValidationException $exception) {
            Log::warning('POS walk-in statutory invoice issue failed', [
                'sale_id' => $sale->id,
                'errors' => $exception->errors(),
            ]);

            $errors = [];
            foreach ($exception->errors() as $messages) {
                $errors = array_merge($errors, $messages);
            }

            return new PosWalkInCompletionResult(
                $sale,
                null,
                array_values(array_unique($errors)),
            );
        } catch (Throwable $exception) {
            Log::error('POS walk-in statutory invoice issue failed', [
                'sale_id' => $sale->id,
                'message' => $exception->getMessage(),
            ]);

            return new PosWalkInCompletionResult($sale, null, [$exception->getMessage()]);
        }
    }
}
