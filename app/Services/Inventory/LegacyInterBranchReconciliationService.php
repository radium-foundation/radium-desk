<?php

namespace App\Services\Inventory;

use App\Enums\InterBranchEwayBillStatus;
use App\Enums\InterBranchReconciliationMode;
use App\Enums\InterBranchTransactionStatus;
use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\InventoryTransferStatus;
use App\Enums\LegacyInterBranchCandidateStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InterBranchReconciliationAudit;
use App\Models\InterBranchTransaction;
use App\Models\InterBranchTransactionLine;
use App\Models\InventoryBranch;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\InventoryTransfer;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\Data\LegacyInterBranchAssessment;
use App\Services\Inventory\Data\LegacyInterBranchCandidate;
use App\Services\Inventory\Data\LegacyInterBranchReconciliationResult;
use App\Services\StatutoryInvoice\BuyerGstin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegacyInterBranchReconciliationService
{
    public const FINANCE_TREATMENT_ORIGINAL_JOURNAL_PRESERVED = 'original_journal_preserved_metadata_only';

    public function __construct(
        private readonly InventoryStockService $stock,
    ) {}

    public function idempotencyKeyForSale(int $saleId): string
    {
        return 'legacy-ibt:sale:'.$saleId;
    }

    /**
     * @return list<LegacyInterBranchCandidate>
     */
    public function discoverCandidates(): array
    {
        $sourceCodes = $this->sourceBranchCodes();
        $sales = InventorySale::query()
            ->where('status', InventorySaleStatus::Completed)
            ->whereNotNull('buyer_gstin')
            ->whereHas('branch', fn ($query) => $query->whereIn('code', $sourceCodes))
            ->with(['branch', 'statutoryInvoice.einvoiceRecord', 'serials.serial'])
            ->orderBy('id')
            ->get();

        $candidates = [];
        foreach ($sales as $sale) {
            $assessment = $this->assess($sale);
            $candidates[] = new LegacyInterBranchCandidate(
                saleId: $sale->id,
                saleNo: $sale->sale_no,
                invoiceId: $assessment->invoice?->id,
                invoiceNumber: $assessment->invoice?->invoice_number,
                status: $assessment->status,
                serialCount: $assessment->serialCount,
                blockers: $assessment->blockers,
            );
        }

        return $candidates;
    }

    public function assess(InventorySale $sale, bool $skipSerialLocationChecks = false): LegacyInterBranchAssessment
    {
        $sale->loadMissing([
            'branch',
            'statutoryInvoice.einvoiceRecord',
            'serials.serial.product',
            'serials.serial.branch',
            'financeJournal',
        ]);

        $blockers = [];
        $invoice = $this->resolveLinkedInvoice($sale, $blockers);
        $fromBranch = $sale->branch;
        $toBranch = $this->resolveDestinationBranch($sale, $fromBranch, $blockers);

        if ($sale->status !== InventorySaleStatus::Completed) {
            $blockers[] = 'Sale is not completed.';
        }

        if ($fromBranch === null || ! $fromBranch->is_active) {
            $blockers[] = 'Source branch is missing or inactive.';
        } elseif (! in_array($fromBranch->code, $this->sourceBranchCodes(), true)) {
            $blockers[] = 'Source branch is not an authorized legacy inter-branch origin.';
        }

        if ($toBranch === null) {
            $blockers[] = 'Destination branch could not be resolved from buyer GSTIN.';
        } elseif ($fromBranch !== null && $toBranch->id === $fromBranch->id) {
            $blockers[] = 'Destination branch must differ from source branch.';
        }

        if ($invoice !== null) {
            if ($invoice->status !== StatutoryInvoiceStatus::Issued) {
                $blockers[] = 'Statutory invoice is not issued.';
            }

            if ((int) $invoice->inventory_sale_id !== (int) $sale->id) {
                $blockers[] = 'Statutory invoice is not linked to this sale.';
            }

            if ($fromBranch !== null && $invoice->seller_gstin !== null && $fromBranch->gstin !== null) {
                $seller = BuyerGstin::normalize($invoice->seller_gstin);
                $branchSeller = BuyerGstin::normalize($fromBranch->gstin);
                if ($seller !== null && $branchSeller !== null && $seller !== $branchSeller) {
                    $blockers[] = 'Invoice seller GSTIN does not match source branch.';
                }
            }

            if ($toBranch !== null && $invoice->buyer_gstin !== null && $toBranch->gstin !== null) {
                $buyer = BuyerGstin::normalize($invoice->buyer_gstin);
                $branchBuyer = BuyerGstin::normalize($toBranch->gstin);
                if ($buyer !== null && $branchBuyer !== null && $buyer !== $branchBuyer) {
                    $blockers[] = 'Invoice buyer GSTIN does not match destination branch.';
                }
            }

            if ($this->requiresIrn() && ($invoice->einvoiceRecord === null || ! $invoice->einvoiceRecord->hasIssuedIrn())) {
                $blockers[] = 'Statutory invoice does not have a submitted IRN.';
            }
        }

        $serials = $this->saleSerials($sale);
        $serialCount = $serials->count();

        if ($serialCount === 0) {
            $blockers[] = 'Sale has no serialized lines to reconcile.';
        }

        if (! $skipSerialLocationChecks) {
            if ($fromBranch !== null) {
                foreach ($serials as $serial) {
                    if ($serial->branch_id !== $fromBranch->id) {
                        $blockers[] = "Serial {$serial->serial_number} is not at source branch {$fromBranch->code}.";
                    }

                    if ($serial->status !== InventorySerialStatus::Sold) {
                        $blockers[] = "Serial {$serial->serial_number} is {$serial->status->label()}, expected Sold at source.";
                    }
                }
            }

            if ($toBranch !== null) {
                foreach ($serials as $serial) {
                    if ($serial->branch_id === $toBranch->id && $serial->status === InventorySerialStatus::Sold) {
                        $blockers[] = "Serial {$serial->serial_number} is already sold at destination.";
                    }
                }
            }
        }

        $existingLegacy = InterBranchTransaction::query()
            ->where('legacy_inventory_sale_id', $sale->id)
            ->first();
        if ($existingLegacy !== null) {
            $blockers[] = 'Sale already has a legacy inter-branch reconciliation (IBT '.$existingLegacy->transaction_no.').';
        }

        if ($serials->isNotEmpty()) {
            $serialIds = $serials->pluck('id');
            $activeConflict = InterBranchTransactionLine::query()
                ->whereIn('serial_id', $serialIds)
                ->whereHas('transaction', function ($query): void {
                    $query->whereIn('status', array_map(
                        fn (InterBranchTransactionStatus $status): string => $status->value,
                        InterBranchTransactionStatus::activeStockHolding(),
                    ));
                })
                ->exists();
            if ($activeConflict) {
                $blockers[] = 'One or more serials are already part of an active inter-branch transfer.';
            }
        }

        $financeJournalId = $sale->finance_journal_id;
        $financeTreatment = self::FINANCE_TREATMENT_ORIGINAL_JOURNAL_PRESERVED;

        $status = $this->classifyStatus($blockers, $existingLegacy !== null);

        return new LegacyInterBranchAssessment(
            status: $status,
            sale: $sale,
            invoice: $invoice,
            fromBranch: $fromBranch,
            toBranch: $toBranch,
            serialCount: $serialCount,
            blockers: array_values(array_unique($blockers)),
            existingIrn: $invoice?->einvoiceRecord?->irn,
            ewayStatus: InterBranchEwayBillStatus::NotApplicable->value,
            financeTreatment: $financeTreatment,
            financeJournalId: $financeJournalId,
        );
    }

    public function reconcile(
        User $actor,
        ?int $saleId = null,
        ?int $invoiceId = null,
        bool $dryRun = false,
        ?string $reason = null,
    ): LegacyInterBranchReconciliationResult {
        $sale = $this->resolveSaleIdentifier($saleId, $invoiceId);

        $existing = InterBranchTransaction::query()
            ->where('legacy_inventory_sale_id', $sale->id)
            ->with(['lines', 'statutoryInvoice', 'inventoryTransfer', 'reconciliationAudits', 'fromBranch', 'toBranch'])
            ->first();
        if ($existing !== null) {
            return new LegacyInterBranchReconciliationResult(
                assessment: $this->assess($sale, skipSerialLocationChecks: true),
                dryRun: false,
                transaction: $existing,
                audit: $existing->reconciliationAudits->first(),
                idempotentReplay: true,
            );
        }

        $assessment = $this->assess($sale);

        if ($dryRun) {
            return new LegacyInterBranchReconciliationResult(
                assessment: $assessment,
                dryRun: true,
            );
        }

        if (! $assessment->isReconcilable()) {
            throw ValidationException::withMessages([
                'reconciliation' => $assessment->blockers[0] ?? 'Legacy inter-branch reconciliation is blocked.',
            ]);
        }

        $invoice = $assessment->invoice;
        $fromBranch = $assessment->fromBranch;
        $toBranch = $assessment->toBranch;
        if ($invoice === null || $fromBranch === null || $toBranch === null) {
            throw ValidationException::withMessages([
                'reconciliation' => 'Legacy inter-branch reconciliation prerequisites are missing.',
            ]);
        }

        $idempotencyKey = $this->idempotencyKeyForSale($sale->id);
        $beforeState = $this->captureState($sale, $invoice, $fromBranch, $toBranch);

        $transaction = DB::transaction(function () use (
            $sale,
            $invoice,
            $fromBranch,
            $toBranch,
            $actor,
            $idempotencyKey,
            $reason,
            $beforeState,
            $assessment,
        ): InterBranchTransaction {
            $duplicate = InterBranchTransaction::query()
                ->where('legacy_inventory_sale_id', $sale->id)
                ->lockForUpdate()
                ->first();
            if ($duplicate !== null) {
                return $duplicate;
            }

            $idemDuplicate = InterBranchTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($idemDuplicate !== null) {
                return $idemDuplicate;
            }

            InventorySale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            StatutoryInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $serials = $this->saleSerials($sale->fresh(['serials.serial.product', 'serials.serial.branch']));
            foreach ($serials as $serial) {
                $this->stock->lockSerialById($serial->id);
            }

            $destinationGstin = BuyerGstin::normalize($toBranch->gstin);
            $now = now();

            $transaction = InterBranchTransaction::query()->create([
                'transaction_no' => 'IBT-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                'idempotency_key' => $idempotencyKey,
                'from_branch_id' => $fromBranch->id,
                'to_branch_id' => $toBranch->id,
                'status' => InterBranchTransactionStatus::InTransit,
                'destination_gstin' => $destinationGstin,
                'statutory_invoice_id' => $invoice->id,
                'legacy_inventory_sale_id' => $sale->id,
                'reconciliation_mode' => InterBranchReconciliationMode::LegacyPosInterBranch,
                'reconciled_at' => $now,
                'reconciled_by' => $actor->id,
                'notes' => 'Legacy POS inter-branch reconciliation for '.$sale->sale_no,
                'created_by' => $actor->id,
                'issued_at' => $invoice->issued_at ?? $now,
                'dispatched_at' => $now,
                'received_at' => $now,
                'completed_at' => $now,
                'eway_bill_status' => InterBranchEwayBillStatus::NotApplicable,
            ]);
            $transaction->update(['transaction_no' => sprintf('IBT-%06d', $transaction->id)]);

            $transfer = InventoryTransfer::query()->create([
                'transfer_no' => 'TRF-TMP-'.strtoupper(bin2hex(random_bytes(6))),
                'from_branch_id' => $fromBranch->id,
                'to_branch_id' => $toBranch->id,
                'status' => InventoryTransferStatus::Completed,
                'notes' => 'Legacy inter-branch '.$transaction->transaction_no.' (supersedes PO/GR path)',
                'created_by' => $actor->id,
                'completed_at' => $now,
            ]);
            $transfer->update(['transfer_no' => sprintf('TRF-%06d', $transfer->id)]);

            $transaction->update(['inventory_transfer_id' => $transfer->id]);

            $sale->loadMissing(['lines.product', 'serials.serial']);

            foreach ($sale->lines as $line) {
                if ($line->product?->is_serialized) {
                    foreach ($sale->serials->where('sale_line_id', $line->id) as $saleSerial) {
                        $serial = $this->stock->lockSerialById($saleSerial->serial_id);
                        InterBranchTransactionLine::query()->create([
                            'inter_branch_transaction_id' => $transaction->id,
                            'product_id' => $line->product_id,
                            'variant_id' => $line->variant_id,
                            'serial_id' => $serial->id,
                            'qty' => 1,
                            'unit_price' => (float) $line->unit_price,
                            'gst_percentage' => (float) ($line->gst_percentage ?? $line->product->gst_percentage),
                        ]);

                        $this->stock->dispatchLegacySoldSerialForInterBranch(
                            $serial,
                            $fromBranch,
                            $toBranch,
                            $transfer,
                            $sale,
                            $actor,
                        );
                        $this->stock->receiveLegacyInterBranchSerial(
                            $serial->fresh(),
                            $fromBranch,
                            $toBranch,
                            $transfer,
                            $sale,
                            $actor,
                        );

                        $transfer->lines()->create([
                            'product_id' => $line->product_id,
                            'variant_id' => $line->variant_id,
                            'serial_id' => $serial->id,
                            'qty' => 1,
                        ]);
                    }
                } else {
                    InterBranchTransactionLine::query()->create([
                        'inter_branch_transaction_id' => $transaction->id,
                        'product_id' => $line->product_id,
                        'variant_id' => $line->variant_id,
                        'serial_id' => null,
                        'qty' => (int) $line->qty,
                        'unit_price' => (float) $line->unit_price,
                        'gst_percentage' => (float) ($line->gst_percentage ?? $line->product->gst_percentage),
                    ]);

                    $this->stock->receiveLegacyInterBranchQuantity(
                        $line->product,
                        $fromBranch,
                        $toBranch,
                        (int) $line->qty,
                        $line->variant,
                        $transfer,
                        $sale,
                        $actor,
                    );

                    $transfer->lines()->create([
                        'product_id' => $line->product_id,
                        'variant_id' => $line->variant_id,
                        'serial_id' => null,
                        'qty' => (int) $line->qty,
                    ]);
                }
            }

            $transaction->update(['status' => InterBranchTransactionStatus::Completed]);

            $afterState = $this->captureState($sale->fresh(), $invoice->fresh(), $fromBranch, $toBranch);

            InterBranchReconciliationAudit::query()->create([
                'inter_branch_transaction_id' => $transaction->id,
                'actor_user_id' => $actor->id,
                'inventory_sale_id' => $sale->id,
                'statutory_invoice_id' => $invoice->id,
                'from_branch_id' => $fromBranch->id,
                'to_branch_id' => $toBranch->id,
                'serial_count' => $assessment->serialCount,
                'reconciliation_mode' => InterBranchReconciliationMode::LegacyPosInterBranch,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
                'finance_treatment' => self::FINANCE_TREATMENT_ORIGINAL_JOURNAL_PRESERVED,
                'finance_journal_id' => $sale->finance_journal_id,
                'before_state' => $beforeState,
                'after_state' => $afterState,
                'created_at' => $now,
            ]);

            return $transaction->fresh([
                'fromBranch',
                'toBranch',
                'lines.product',
                'lines.serial',
                'statutoryInvoice.einvoiceRecord',
                'inventoryTransfer.lines',
                'reconciliationAudits',
                'legacyInventorySale',
            ]) ?? $transaction;
        }, 5);

        $audit = $transaction->reconciliationAudits->first();

        return new LegacyInterBranchReconciliationResult(
            assessment: $assessment,
            dryRun: false,
            transaction: $transaction,
            audit: $audit,
            idempotentReplay: false,
        );
    }

    private function resolveSaleIdentifier(?int $saleId, ?int $invoiceId): InventorySale
    {
        if ($saleId === null && $invoiceId === null) {
            throw ValidationException::withMessages([
                'identifier' => 'Provide --sale or --invoice.',
            ]);
        }

        if ($saleId !== null && $invoiceId !== null) {
            $sale = InventorySale::query()->find($saleId);
            $invoice = StatutoryInvoice::query()->find($invoiceId);
            if ($sale === null || $invoice === null || (int) $invoice->inventory_sale_id !== (int) $sale->id) {
                throw ValidationException::withMessages([
                    'identifier' => 'Sale and invoice identifiers do not refer to the same transaction.',
                ]);
            }

            return $sale;
        }

        if ($saleId !== null) {
            $sale = InventorySale::query()->find($saleId);
            if ($sale === null) {
                throw ValidationException::withMessages([
                    'sale' => 'Inventory sale was not found.',
                ]);
            }

            return $sale;
        }

        $invoice = StatutoryInvoice::query()->find($invoiceId);
        if ($invoice === null || $invoice->inventory_sale_id === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Statutory invoice was not found or is not linked to a POS sale.',
            ]);
        }

        return InventorySale::query()->findOrFail($invoice->inventory_sale_id);
    }

    private function resolveLinkedInvoice(InventorySale $sale, array &$blockers): ?StatutoryInvoice
    {
        $invoice = $sale->statutoryInvoice;
        if ($invoice === null && $sale->statutory_invoice_id !== null) {
            $invoice = StatutoryInvoice::query()->find($sale->statutory_invoice_id);
        }

        if ($invoice === null) {
            $blockers[] = 'Statutory invoice is missing.';

            return null;
        }

        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            $blockers[] = 'Statutory invoice is cancelled.';
        }

        return $invoice;
    }

    private function resolveDestinationBranch(
        InventorySale $sale,
        ?InventoryBranch $fromBranch,
        array &$blockers,
    ): ?InventoryBranch {
        $buyerGstin = BuyerGstin::normalize($sale->buyer_gstin);
        if ($buyerGstin === null || ! BuyerGstin::isValid($buyerGstin)) {
            $blockers[] = 'Sale buyer GSTIN is missing or invalid.';

            return null;
        }

        $branches = InventoryBranch::query()
            ->where('is_active', true)
            ->whereNotNull('gstin')
            ->get()
            ->filter(function (InventoryBranch $branch) use ($buyerGstin, $fromBranch): bool {
                if ($fromBranch !== null && $branch->id === $fromBranch->id) {
                    return false;
                }

                return BuyerGstin::normalize($branch->gstin) === $buyerGstin;
            })
            ->values();

        if ($branches->count() === 0) {
            return null;
        }

        if ($branches->count() > 1) {
            $blockers[] = 'Buyer GSTIN matches multiple destination branches.';

            return null;
        }

        return $branches->first();
    }

    /**
     * @return Collection<int, InventorySerial>
     */
    private function saleSerials(InventorySale $sale): Collection
    {
        return $sale->serials
            ->map(fn ($saleSerial) => $saleSerial->serial)
            ->filter()
            ->values();
    }

    /**
     * @param  list<string>  $blockers
     */
    private function classifyStatus(array $blockers, bool $alreadyReconciled): LegacyInterBranchCandidateStatus
    {
        if ($alreadyReconciled) {
            return LegacyInterBranchCandidateStatus::AlreadyReconciled;
        }

        if ($blockers === []) {
            return LegacyInterBranchCandidateStatus::Reconcilable;
        }

        $manualReviewMarkers = [
            'Buyer GSTIN matches multiple destination branches.',
        ];

        foreach ($blockers as $blocker) {
            if (in_array($blocker, $manualReviewMarkers, true)) {
                return LegacyInterBranchCandidateStatus::RequiresManualReview;
            }
        }

        return LegacyInterBranchCandidateStatus::Blocked;
    }

    /**
     * @return list<string>
     */
    private function sourceBranchCodes(): array
    {
        $codes = config('inter_branch.legacy_reconciliation.source_branch_codes', ['DELHI-RETAIL']);

        return is_array($codes) ? array_values(array_filter($codes, fn ($code): bool => is_string($code) && $code !== '')) : ['DELHI-RETAIL'];
    }

    private function requiresIrn(): bool
    {
        return (bool) config('inter_branch.legacy_reconciliation.require_irn', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function captureState(
        InventorySale $sale,
        StatutoryInvoice $invoice,
        InventoryBranch $fromBranch,
        InventoryBranch $toBranch,
    ): array {
        $serials = $this->saleSerials($sale->loadMissing(['serials.serial']));

        return [
            'sale_id' => $sale->id,
            'sale_no' => $sale->sale_no,
            'sale_status' => $sale->status->value,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_status' => $invoice->status->value,
            'irn' => $invoice->einvoiceRecord?->irn,
            'finance_journal_id' => $sale->finance_journal_id,
            'from_branch_code' => $fromBranch->code,
            'to_branch_code' => $toBranch->code,
            'serials' => $serials->map(fn (InventorySerial $serial): array => [
                'serial_number' => $serial->serial_number,
                'branch_id' => $serial->branch_id,
                'status' => $serial->status->value,
            ])->all(),
        ];
    }
}
