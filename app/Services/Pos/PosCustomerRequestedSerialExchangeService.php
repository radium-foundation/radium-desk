<?php

namespace App\Services\Pos;

use App\Enums\InventoryMovementType;
use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\PosCustomerRequestedSerialExchangeStatus;
use App\Enums\PosCustomerRequestedSerialExchangeStatutoryTreatment;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventorySale;
use App\Models\InventorySaleSerial;
use App\Models\InventorySerial;
use App\Models\PosCustomerRequestedSerialExchange;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\ReturnExchange\ReturnExchangeCaseSerialReplacementEquivalence;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\Inventory\InventorySerialNumber;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PosCustomerRequestedSerialExchangeService
{
    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly ReturnExchangeCaseSerialReplacementEquivalence $equivalence,
    ) {}

    /**
     * @param  array{
     *     inventory_sale_id: int,
     *     original_serial_number: string,
     *     replacement_serial_number: string,
     *     idempotency_key: string,
     *     exchange_reason: string,
     *     statutory_treatment_decision: string,
     *     statutory_evidence_reference: string,
     *     statutory_notes?: string|null,
     * }  $payload
     */
    public function recordStatutoryApproval(User $actor, array $payload): PosCustomerRequestedSerialExchange
    {
        $this->assertPermission($actor, RolePermissionSeeder::PERMISSION_POS_CUSTOMER_SERIAL_EXCHANGE_STATUTORY);

        $idempotencyKey = trim((string) $payload['idempotency_key']);
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key is required.',
            ]);
        }

        $existing = PosCustomerRequestedSerialExchange::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing?->executed_at !== null) {
            throw ValidationException::withMessages([
                'exchange' => 'This exchange has already been executed.',
            ]);
        }
        if ($existing?->statutory_approved_at !== null) {
            return $existing->fresh([
                'sale',
                'originalSerial',
                'replacementSerial',
            ]);
        }

        $treatment = PosCustomerRequestedSerialExchangeStatutoryTreatment::tryFrom(
            trim((string) $payload['statutory_treatment_decision'])
        );
        if ($treatment === null) {
            throw ValidationException::withMessages([
                'statutory_treatment_decision' => 'Select a valid statutory treatment decision.',
            ]);
        }

        $evidence = trim((string) $payload['statutory_evidence_reference']);
        if ($evidence === '') {
            throw ValidationException::withMessages([
                'statutory_evidence_reference' => 'Statutory evidence reference is required.',
            ]);
        }

        if (! $treatment->permitsSerialOnlyExecution()) {
            throw ValidationException::withMessages([
                'statutory_treatment_decision' => $treatment->label().' This service cannot perform invoice or journal changes.',
            ]);
        }

        try {
            return DB::transaction(function () use ($actor, $payload, $idempotencyKey, $treatment, $evidence): PosCustomerRequestedSerialExchange {
                $dup = PosCustomerRequestedSerialExchange::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($dup?->statutory_approved_at !== null) {
                    return $dup;
                }

                $exchange = $dup ?? $this->createExchangeDraft($actor, $payload, $idempotencyKey);
                $exchange->loadMissing('sale');

                if (! $this->saleRequiresStatutoryGate($exchange->sale)) {
                    throw ValidationException::withMessages([
                        'statutory' => 'This sale does not have an issued statutory invoice; statutory approval is not applicable.',
                    ]);
                }

                $exchange->update([
                    'statutory_treatment_decision' => $treatment,
                    'statutory_evidence_reference' => $evidence,
                    'statutory_notes' => isset($payload['statutory_notes']) ? (string) $payload['statutory_notes'] : null,
                    'statutory_approved_by' => $actor->id,
                    'statutory_approved_at' => now(),
                    'status' => PosCustomerRequestedSerialExchangeStatus::Ready,
                ]);

                return $exchange->fresh([
                    'sale',
                    'originalSerial',
                    'replacementSerial',
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return PosCustomerRequestedSerialExchange::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    /**
     * @param  array{
     *     idempotency_key: string,
     *     inventory_sale_id: int,
     *     original_serial_number: string,
     *     replacement_serial_number: string,
     *     exchange_reason: string,
     *     physical_receipt_attestation_reason: string,
     * }  $payload
     */
    public function execute(User $actor, array $payload): PosCustomerRequestedSerialExchange
    {
        $this->assertPermission($actor, RolePermissionSeeder::PERMISSION_POS_CUSTOMER_SERIAL_EXCHANGE_EXECUTE);
        $this->assertPermission($actor, RolePermissionSeeder::PERMISSION_POS_CUSTOMER_SERIAL_EXCHANGE_ATTEST);

        $idempotencyKey = trim((string) $payload['idempotency_key']);
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key is required.',
            ]);
        }

        $attestation = trim((string) $payload['physical_receipt_attestation_reason']);
        if ($attestation === '') {
            throw ValidationException::withMessages([
                'physical_receipt_attestation_reason' => 'Confirm physical receipt and resaleable condition.',
            ]);
        }

        $existing = PosCustomerRequestedSerialExchange::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing?->executed_at !== null) {
            return $existing->load(['sale', 'originalSerial', 'replacementSerial']);
        }

        try {
            return $this->runWithInnodbDeadlockRetry(function () use ($actor, $payload, $idempotencyKey, $attestation): PosCustomerRequestedSerialExchange {
                return DB::transaction(function () use ($actor, $payload, $idempotencyKey, $attestation): PosCustomerRequestedSerialExchange {
                    $exchange = PosCustomerRequestedSerialExchange::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($exchange?->executed_at !== null) {
                        return $exchange;
                    }

                    if ($exchange === null) {
                        $exchange = $this->createExchangeDraft($actor, $payload, $idempotencyKey);
                        $exchange = PosCustomerRequestedSerialExchange::query()
                            ->whereKey($exchange->id)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }

                    if ($this->saleRequiresStatutoryGate($exchange->sale) && $exchange->statutory_approved_at === null) {
                        throw ValidationException::withMessages([
                            'statutory' => 'Statutory treatment approval is required before this exchange can run.',
                        ]);
                    }

                    if ($exchange->status === PosCustomerRequestedSerialExchangeStatus::AwaitingStatutory) {
                        throw ValidationException::withMessages([
                            'statutory' => 'Statutory treatment approval is required before this exchange can run.',
                        ]);
                    }

                    $sale = InventorySale::query()
                        ->whereKey($exchange->inventory_sale_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $this->assertSaleUnchanged($sale, $exchange);

                    $assignment = InventorySaleSerial::query()
                        ->whereKey($exchange->inventory_sale_serial_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ((int) $assignment->serial_id !== (int) $exchange->original_inventory_serial_id) {
                        throw ValidationException::withMessages([
                            'original_serial_number' => 'The original serial is no longer assigned to this sale.',
                        ]);
                    }

                    $original = InventorySerial::query()
                        ->lockForUpdate()
                        ->findOrFail($exchange->original_inventory_serial_id);
                    $replacement = InventorySerial::query()
                        ->lockForUpdate()
                        ->findOrFail($exchange->replacement_inventory_serial_id);

                    $branch = $exchange->branch;

                    if ($original->status !== InventorySerialStatus::Sold) {
                        throw ValidationException::withMessages([
                            'original_serial_number' => 'Original serial is not in sold status.',
                        ]);
                    }

                    if (! $replacement->status->isAssignable()) {
                        throw ValidationException::withMessages([
                            'replacement_serial_number' => 'Replacement serial is not available.',
                        ]);
                    }

                    if ((int) $original->branch_id !== (int) $branch->id || (int) $replacement->branch_id !== (int) $branch->id) {
                        throw ValidationException::withMessages([
                            'branch' => 'Both serials must be at the sale branch.',
                        ]);
                    }

                    if (! $this->equivalence->productsMatch($original, $replacement)) {
                        throw ValidationException::withMessages([
                            'replacement_serial_number' => 'Replacement serial is not compatible with the original product.',
                        ]);
                    }

                    $notes = 'Customer-requested serial exchange. '.$exchange->exchange_reason;

                    $fromStatus = $original->status;
                    $this->stock->restoreSerialFromSale($original, $branch);
                    $original->refresh();
                    $this->stock->recordMovement(
                        type: InventoryMovementType::PosCustomerSerialExchangeRestore,
                        product: $original->product,
                        branch: $branch,
                        qty: 1,
                        actor: $actor,
                        variant: $original->variant,
                        serial: $original,
                        sale: $sale,
                        fromStatus: $fromStatus,
                        toStatus: InventorySerialStatus::Available,
                        notes: $notes.' Original restored to available inventory.',
                    );

                    $replacementFrom = $replacement->status;
                    $this->stock->markSerialSold($replacement, $branch);
                    $replacement->refresh();
                    $this->stock->recordMovement(
                        type: InventoryMovementType::PosCustomerSerialExchangeAssign,
                        product: $replacement->product,
                        branch: $branch,
                        qty: -1,
                        actor: $actor,
                        variant: $replacement->variant,
                        serial: $replacement,
                        sale: $sale,
                        fromStatus: $replacementFrom,
                        toStatus: InventorySerialStatus::Sold,
                        notes: $notes.' Replacement assigned to existing sale.',
                    );

                    $assignment->update(['serial_id' => $replacement->id]);

                    $exchange->update([
                        'physical_receipt_attestation_reason' => $attestation,
                        'physical_receipt_attested_by' => $actor->id,
                        'physical_receipt_attested_at' => now(),
                        'performed_by' => $actor->id,
                        'executed_at' => now(),
                        'status' => PosCustomerRequestedSerialExchangeStatus::Executed,
                    ]);

                    return $exchange->fresh([
                        'sale',
                        'originalSerial',
                        'replacementSerial',
                        'saleSerialAssignment',
                    ]);
                });
            });
        } catch (UniqueConstraintViolationException) {
            return PosCustomerRequestedSerialExchange::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    /**
     * @param  array{
     *     inventory_sale_id: int,
     *     original_serial_number: string,
     *     replacement_serial_number: string,
     *     exchange_reason: string,
     * }  $payload
     */
    private function createExchangeDraft(User $actor, array $payload, string $idempotencyKey): PosCustomerRequestedSerialExchange
    {
        $saleId = (int) $payload['inventory_sale_id'];
        $reason = trim((string) $payload['exchange_reason']);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'exchange_reason' => 'Enter the exchange reason.',
            ]);
        }

        $sale = InventorySale::query()
            ->whereKey($saleId)
            ->lockForUpdate()
            ->with(['branch'])
            ->firstOrFail();

        InventoryBranchScope::assertCanOperate($actor, $sale->branch);

        if ($sale->status !== InventorySaleStatus::Completed) {
            throw ValidationException::withMessages([
                'inventory_sale_id' => 'Only completed POS sales support customer-requested serial exchange.',
            ]);
        }

        $originalNumber = InventorySerialNumber::normalize(trim((string) $payload['original_serial_number']));
        $replacementNumber = InventorySerialNumber::normalize(trim((string) $payload['replacement_serial_number']));

        if ($originalNumber === '' || $replacementNumber === '') {
            throw ValidationException::withMessages([
                'serial' => 'Original and replacement serial numbers are required.',
            ]);
        }

        if ($originalNumber === $replacementNumber) {
            throw ValidationException::withMessages([
                'replacement_serial_number' => 'Replacement serial must differ from the original.',
            ]);
        }

        $original = $this->stock->lockSerialByNumber($originalNumber);
        $original = InventorySerial::query()->lockForUpdate()->findOrFail($original->id);
        $replacement = $this->stock->lockSerialByNumber($replacementNumber);
        $replacement = InventorySerial::query()->lockForUpdate()->findOrFail($replacement->id);

        $assignment = InventorySaleSerial::query()
            ->where('sale_id', $sale->id)
            ->where('serial_id', $original->id)
            ->lockForUpdate()
            ->first();

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'original_serial_number' => 'Original serial is not assigned to this sale.',
            ]);
        }

        if ($original->status !== InventorySerialStatus::Sold) {
            throw ValidationException::withMessages([
                'original_serial_number' => 'Original serial must currently be sold on this sale.',
            ]);
        }

        if (! $replacement->status->isAssignable()) {
            throw ValidationException::withMessages([
                'replacement_serial_number' => 'Replacement serial is not available.',
            ]);
        }

        if ((int) $original->branch_id !== (int) $sale->branch_id || (int) $replacement->branch_id !== (int) $sale->branch_id) {
            throw ValidationException::withMessages([
                'branch' => 'Serials must belong to the sale branch.',
            ]);
        }

        if (! $this->equivalence->productsMatch($original, $replacement)) {
            throw ValidationException::withMessages([
                'replacement_serial_number' => 'Replacement serial is not compatible with the original product.',
            ]);
        }

        $duplicateAssignment = InventorySaleSerial::query()
            ->where('sale_id', $sale->id)
            ->where('serial_id', $replacement->id)
            ->exists();
        if ($duplicateAssignment) {
            throw ValidationException::withMessages([
                'replacement_serial_number' => 'Replacement serial is already on this sale.',
            ]);
        }

        $requiresStatutory = $this->saleRequiresStatutoryGate($sale);
        $status = $requiresStatutory
            ? PosCustomerRequestedSerialExchangeStatus::AwaitingStatutory
            : PosCustomerRequestedSerialExchangeStatus::Ready;

        return PosCustomerRequestedSerialExchange::query()->create([
            'inventory_sale_id' => $sale->id,
            'inventory_branch_id' => $sale->branch_id,
            'original_inventory_serial_id' => $original->id,
            'replacement_inventory_serial_id' => $replacement->id,
            'inventory_sale_serial_id' => $assignment->id,
            'idempotency_key' => $idempotencyKey,
            'exchange_reason' => $reason,
            'status' => $status,
            'sale_total_snapshot' => $sale->total,
            'sale_serial_count_snapshot' => InventorySaleSerial::query()->where('sale_id', $sale->id)->count(),
        ]);
    }

    private function saleRequiresStatutoryGate(InventorySale $sale): bool
    {
        return StatutoryInvoice::query()
            ->where('inventory_sale_id', $sale->id)
            ->where('document_type', StatutoryInvoiceDocumentType::TaxInvoice)
            ->where('status', StatutoryInvoiceStatus::Issued)
            ->exists();
    }

    private function assertSaleUnchanged(InventorySale $sale, PosCustomerRequestedSerialExchange $exchange): void
    {
        if (bccomp((string) $sale->total, (string) $exchange->sale_total_snapshot, 2) !== 0) {
            throw ValidationException::withMessages([
                'sale' => 'Sale total changed since exchange was prepared.',
            ]);
        }

        $count = InventorySaleSerial::query()->where('sale_id', $sale->id)->count();
        if ($count !== (int) $exchange->sale_serial_count_snapshot) {
            throw ValidationException::withMessages([
                'sale' => 'Sale serial count changed since exchange was prepared.',
            ]);
        }
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw ValidationException::withMessages([
                'authorization' => 'You are not allowed to perform this action.',
            ]);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function runWithInnodbDeadlockRetry(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return $callback();
            } catch (QueryException $exception) {
                if ($attempt < 5 && $this->isInnodbDeadlock($exception)) {
                    usleep(25_000 * $attempt);

                    continue;
                }

                throw $exception;
            }
        }

        throw new \RuntimeException('InnoDB deadlock retry loop exhausted.');
    }

    private function isInnodbDeadlock(Throwable $exception): bool
    {
        if (! $exception instanceof QueryException) {
            return false;
        }

        $code = (int) $exception->getCode();
        if ($code === 1213 || $code === 1205) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'deadlock') || str_contains($message, 'lock wait');
    }
}
