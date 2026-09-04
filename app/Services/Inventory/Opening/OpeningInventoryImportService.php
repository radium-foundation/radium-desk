<?php

namespace App\Services\Inventory\Opening;

use App\Enums\InventoryOpeningImportStatus;
use App\Enums\InventorySerialStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryOpeningImportBatch;
use App\Models\InventoryOpeningImportRow;
use App\Models\InventoryProduct;
use App\Models\InventoryProductVariant;
use App\Models\InventorySerial;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\Opening\Data\OpeningInventoryCountRow;
use App\Services\Inventory\Opening\Data\OpeningInventoryImportResult;
use App\Services\Inventory\Opening\Data\OpeningInventoryIssue;
use App\Services\Inventory\Opening\Data\OpeningInventoryReconciliation;
use App\Services\Inventory\Opening\Data\OpeningInventorySkuRow;
use App\Services\Inventory\Opening\Data\OpeningInventoryWorkbook;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningInventoryImportService
{
    public function __construct(
        private readonly OpeningInventoryWorkbookReader $reader,
        private readonly InventoryStockService $stock,
    ) {}

    public function preview(string $path, User $actor, ?string $filename = null, ?string $storedPath = null): OpeningInventoryImportResult
    {
        $workbook = $this->reader->read($path, $filename);

        return $this->persistPreview($workbook, $actor, $storedPath);
    }

    public function apply(string $path, User $actor, ?string $filename = null, ?string $storedPath = null): OpeningInventoryImportResult
    {
        $workbook = $this->reader->read($path, $filename);
        $preview = $this->persistPreview($workbook, $actor, $storedPath);

        if ($preview->alreadyApplied) {
            return $preview;
        }

        if (! $preview->canApply) {
            $preview->batch->update(['status' => InventoryOpeningImportStatus::Blocked]);

            throw ValidationException::withMessages([
                'workbook' => 'Opening import is blocked. Fix every Row Issue first. Stock was not changed.',
            ]);
        }

        try {
            return DB::transaction(function () use ($workbook, $preview, $actor): OpeningInventoryImportResult {
                $batch = InventoryOpeningImportBatch::query()
                    ->whereKey($preview->batch->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($batch->status === InventoryOpeningImportStatus::Applied) {
                    return $this->resultFromBatch($batch, $preview->issues, alreadyApplied: true);
                }

                $catalog = $this->applySkuMaster($workbook);
                $applied = 0;

                foreach ($workbook->openingRows as $row) {
                    $product = $this->resolveProduct($row);
                    $variant = $this->resolveVariant($row, $product);
                    $branch = $this->requireBranch($row->branchCode);
                    $notes = $this->openingNotes($row);
                    $occurredAt = $row->openingDate?->startOfDay();

                    if ($product->is_serialized) {
                        $serial = $this->stock->receiveOpeningSerialized(
                            product: $product,
                            branch: $branch,
                            serialNumber: $row->serialNumber,
                            actor: $actor,
                            condition: $row->condition,
                            status: $row->stockStatus ?? InventorySerialStatus::Available,
                            variant: $variant,
                            unitCost: $row->unitCost,
                            notes: $notes,
                            occurredAt: $occurredAt,
                            openingImportBatchId: $batch->id,
                        );
                        $this->markRowApplied(
                            $batch,
                            $row,
                            $product,
                            $serial->id,
                            $serial->movements()->latest('id')->value('id'),
                        );
                    } else {
                        $this->stock->receiveOpeningQuantity(
                            product: $product,
                            branch: $branch,
                            qty: (int) $row->quantity,
                            actor: $actor,
                            variant: $variant,
                            notes: $notes,
                            occurredAt: $occurredAt,
                            openingImportBatchId: $batch->id,
                        );
                        $movementId = InventoryMovement::query()
                            ->where('opening_import_batch_id', $batch->id)
                            ->where('product_id', $product->id)
                            ->where('branch_id', $branch->id)
                            ->latest('id')
                            ->value('id');
                        $this->markRowApplied($batch, $row, $product, null, $movementId);
                    }

                    $applied++;
                }

                $batch->update([
                    'status' => InventoryOpeningImportStatus::Applied,
                    'sku_created_count' => $catalog['skus'],
                    'variant_created_count' => $catalog['variants'],
                    'rows_applied' => $applied,
                    'actor_user_id' => $actor->id,
                    'applied_at' => now(),
                ]);

                return $this->resultFromBatch($batch->fresh(), $preview->issues, alreadyApplied: false, rowsApplied: $applied);
            });
        } catch (ValidationException $exception) {
            $preview->batch->update(['status' => InventoryOpeningImportStatus::Failed]);
            throw $exception;
        }
    }

    private function persistPreview(
        OpeningInventoryWorkbook $workbook,
        User $actor,
        ?string $storedPath,
    ): OpeningInventoryImportResult {
        $issues = [...$workbook->parseIssues, ...$this->validate($workbook)];
        $blocking = array_values(array_filter($issues, fn (OpeningInventoryIssue $issue): bool => $issue->blocking));
        $invalidRows = count(array_unique(array_map(
            fn (OpeningInventoryIssue $issue): string => $issue->sheet.'#'.$issue->rowNumber,
            array_filter($blocking, fn (OpeningInventoryIssue $issue): bool => $issue->rowNumber > 0),
        )));

        $batch = InventoryOpeningImportBatch::query()->firstOrNew([
            'source_checksum' => $workbook->checksum,
        ]);

        if ($batch->exists && $batch->status === InventoryOpeningImportStatus::Applied) {
            return $this->resultFromBatch($batch, $issues, alreadyApplied: true);
        }

        $openingDates = array_values(array_filter(array_map(
            fn (OpeningInventoryCountRow $row) => $row->openingDate?->toDateString(),
            $workbook->openingRows,
        )));
        $reconciliation = OpeningInventoryReconciliation::fromRows($workbook->openingRows, $issues);

        $batch->fill([
            'source_filename' => $workbook->filename,
            'stored_path' => $storedPath ?? $batch->stored_path,
            'status' => $blocking === [] && $workbook->openingRows !== []
                ? InventoryOpeningImportStatus::Previewed
                : InventoryOpeningImportStatus::Blocked,
            'opening_date' => $openingDates[0] ?? null,
            'rows_valid' => max(0, count($workbook->openingRows) - $invalidRows),
            'rows_invalid' => $invalidRows,
            'summary' => [
                'opening_rows' => count($workbook->openingRows),
                'sku_rows' => count($workbook->skuRows),
                'branch_rows' => count($workbook->branchRows),
                'blocking' => count($blocking),
                'warnings' => count($issues) - count($blocking),
                'reconciliation' => $reconciliation->toArray(),
            ],
            'actor_user_id' => $actor->id,
        ]);
        $batch->save();

        $batch->rows()->delete();
        foreach ($workbook->openingRows as $row) {
            $rowIssues = array_values(array_filter(
                $issues,
                fn (OpeningInventoryIssue $issue): bool => $issue->sheet === OpeningInventoryTemplate::SHEET_OPENING
                    && $issue->rowNumber === $row->rowNumber,
            ));

            InventoryOpeningImportRow::query()->create([
                'batch_id' => $batch->id,
                'sheet' => OpeningInventoryTemplate::SHEET_OPENING,
                'row_number' => $row->rowNumber,
                'fingerprint' => $row->fingerprint(),
                'sku' => $row->sku !== '' ? $row->sku : null,
                'variant_sku' => $row->variantSku !== '' ? $row->variantSku : null,
                'branch_code' => $row->branchCode !== '' ? $row->branchCode : null,
                'serial_number' => $row->serialNumber !== '' ? $row->serialNumber : null,
                'qty' => $row->quantity,
                'status' => $rowIssues === [] ? 'valid' : 'invalid',
                'issues' => array_map(fn (OpeningInventoryIssue $issue): string => $issue->message, $rowIssues),
            ]);
        }

        return new OpeningInventoryImportResult(
            batch: $batch->fresh(),
            canApply: $blocking === [] && $workbook->openingRows !== [],
            alreadyApplied: false,
            openingRows: count($workbook->openingRows),
            validRows: max(0, count($workbook->openingRows) - $invalidRows),
            invalidRows: $invalidRows,
            skuRows: count($workbook->skuRows),
            skusCreated: 0,
            variantsCreated: 0,
            rowsApplied: 0,
            issues: $issues,
            reconciliation: $reconciliation,
        );
    }

    /**
     * @return list<OpeningInventoryIssue>
     */
    private function validate(OpeningInventoryWorkbook $workbook): array
    {
        $issues = [];
        $skuByCode = [];
        $variantBySku = [];

        foreach ($workbook->skuRows as $skuRow) {
            $this->validateSkuRow($skuRow, $skuByCode, $variantBySku, $issues);
        }

        $workbookBranches = [];
        foreach ($workbook->branchRows as $branchRow) {
            $workbookBranches[$branchRow->code] = $branchRow;
        }

        $serials = [];
        $qtyIdentities = [];

        if ($workbook->openingRows === []) {
            $issues[] = new OpeningInventoryIssue(
                sheet: OpeningInventoryTemplate::SHEET_OPENING,
                rowNumber: 0,
                code: 'empty_opening',
                message: 'Inventory Opening has no filled rows.',
            );
        }

        foreach ($workbook->openingRows as $row) {
            $this->validateOpeningRow(
                $row,
                $skuByCode,
                $variantBySku,
                $workbookBranches,
                $serials,
                $qtyIdentities,
                $issues,
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, OpeningInventorySkuRow>  $skuByCode
     * @param  array<string, OpeningInventorySkuRow>  $variantBySku
     * @param  list<OpeningInventoryIssue>  $issues
     */
    private function validateSkuRow(
        OpeningInventorySkuRow $skuRow,
        array &$skuByCode,
        array &$variantBySku,
        array &$issues,
    ): void {
        if ($skuRow->name === '') {
            $issues[] = $this->skuIssue($skuRow, 'sku_name', 'Product Name is required on SKU Master.');
        }
        if ($skuRow->serialized === null) {
            $issues[] = $this->skuIssue($skuRow, 'sku_serialized', 'Serialized must be Y or N.');
        }
        if ($skuRow->gstPercentage === null) {
            $issues[] = $this->skuIssue($skuRow, 'sku_gst', 'GST % is required before a SKU can be created.');
        }
        if ($skuRow->unitPrice === null) {
            $issues[] = $this->skuIssue($skuRow, 'sku_price', 'Default Selling Price is required before a SKU can be created.');
        }
        if ($skuRow->active === null) {
            $issues[] = $this->skuIssue($skuRow, 'sku_active', 'Active must be Y or N.');
        }

        $existing = InventoryProduct::query()->where('sku', $skuRow->sku)->first();
        if ($existing !== null) {
            if ($skuRow->serialized !== null && (bool) $existing->is_serialized !== $skuRow->serialized) {
                $issues[] = $this->skuIssue(
                    $skuRow,
                    'sku_serialized_mismatch',
                    "Desk SKU {$skuRow->sku} serialized flag does not match SKU Master. Existing catalog was not changed.",
                );
            }
            if ($skuRow->gstPercentage !== null && (string) $existing->gst_percentage !== $skuRow->gstPercentage) {
                $issues[] = $this->skuIssue(
                    $skuRow,
                    'sku_gst_mismatch',
                    "Desk SKU {$skuRow->sku} GST % does not match SKU Master. Existing catalog was not changed.",
                );
            }
        }

        if (isset($skuByCode[$skuRow->sku])) {
            $previous = $skuByCode[$skuRow->sku];
            if ($previous->serialized !== $skuRow->serialized || $previous->gstPercentage !== $skuRow->gstPercentage) {
                $issues[] = $this->skuIssue($skuRow, 'sku_duplicate_conflict', "SKU {$skuRow->sku} appears more than once on SKU Master with conflicting values.");
            }
        }
        $skuByCode[$skuRow->sku] = $skuRow;

        if ($skuRow->variantSku !== '') {
            if (isset($variantBySku[$skuRow->variantSku]) && $variantBySku[$skuRow->variantSku]->sku !== $skuRow->sku) {
                $issues[] = $this->skuIssue($skuRow, 'variant_collision', "Variant SKU {$skuRow->variantSku} is used on more than one parent SKU.");
            }
            $variantBySku[$skuRow->variantSku] = $skuRow;
        }
    }

    /**
     * @param  array<string, OpeningInventorySkuRow>  $skuByCode
     * @param  array<string, OpeningInventorySkuRow>  $variantBySku
     * @param  array<string, mixed>  $workbookBranches
     * @param  array<string, int>  $serials
     * @param  array<string, int>  $qtyIdentities
     * @param  list<OpeningInventoryIssue>  $issues
     */
    private function validateOpeningRow(
        OpeningInventoryCountRow $row,
        array $skuByCode,
        array $variantBySku,
        array $workbookBranches,
        array &$serials,
        array &$qtyIdentities,
        array &$issues,
    ): void {
        if ($row->openingDate === null) {
            $issues[] = $this->openingIssue($row, 'opening_date', 'Opening Date is required and must be a date in 2020–2035.');
        }
        if ($row->branchCode === '') {
            $issues[] = $this->openingIssue($row, 'branch', 'Branch Code is required.');
        } else {
            if (! isset($workbookBranches[$row->branchCode])) {
                $issues[] = $this->openingIssue($row, 'branch_sheet', "Branch {$row->branchCode} is not on the workbook Branches sheet.");
            }
            $deskBranch = InventoryBranch::query()->where('code', $row->branchCode)->first();
            if ($deskBranch === null) {
                $issues[] = $this->openingIssue(
                    $row,
                    'branch_missing',
                    "Branch {$row->branchCode} is not in Desk. Create it under Inventory → Branches first. Import does not invent branches or GSTINs.",
                );
            } elseif (! $deskBranch->is_active) {
                $issues[] = $this->openingIssue($row, 'branch_inactive', "Branch {$row->branchCode} is inactive.");
            }
        }

        if ($row->sku === '') {
            $issues[] = $this->openingIssue($row, 'sku', 'SKU is required.');

            return;
        }

        $skuRow = $skuByCode[$row->sku] ?? null;
        $product = InventoryProduct::query()->where('sku', $row->sku)->first();
        if ($skuRow === null && $product === null) {
            $issues[] = $this->openingIssue($row, 'sku_unknown', "SKU {$row->sku} is not on SKU Master and does not exist in Desk.");
        }

        $serialized = $product?->is_serialized ?? $skuRow?->serialized;
        if ($serialized === null) {
            $issues[] = $this->openingIssue($row, 'serialized_unknown', "Cannot determine whether {$row->sku} is serialized.");
        }

        if ($row->rawCondition === '') {
            $issues[] = $this->openingIssue($row, 'condition', 'Condition is required (New, Used, or Refurbished).');
        } elseif ($row->condition === null) {
            $issues[] = $this->openingIssue($row, 'condition_invalid', 'Condition must be New, Used, or Refurbished.');
        }

        if ($row->rawStockStatus === '') {
            $issues[] = $this->openingIssue($row, 'stock_status', 'Stock Status is required (Available or Damaged).');
        } elseif ($row->stockStatus === null) {
            $issues[] = $this->openingIssue($row, 'stock_status_invalid', 'Stock Status must be Available or Damaged. Sold units are not opening stock.');
        }

        if ($row->quantity === null || $row->quantity < 1) {
            $issues[] = $this->openingIssue($row, 'qty', 'Quantity must be an integer greater than 0.');
        }

        if ($row->variantSku !== '') {
            $variantExists = InventoryProductVariant::query()->where('sku', $row->variantSku)->exists()
                || isset($variantBySku[$row->variantSku]);
            if (! $variantExists) {
                $issues[] = $this->openingIssue($row, 'variant_unknown', "Variant SKU {$row->variantSku} is not on SKU Master and does not exist in Desk.");
            } elseif (isset($variantBySku[$row->variantSku]) && $variantBySku[$row->variantSku]->sku !== $row->sku) {
                $issues[] = $this->openingIssue($row, 'variant_parent', "Variant SKU {$row->variantSku} does not belong to {$row->sku}.");
            } else {
                $deskVariant = InventoryProductVariant::query()->where('sku', $row->variantSku)->first();
                if ($deskVariant !== null && $product !== null && (int) $deskVariant->product_id !== (int) $product->id) {
                    $issues[] = $this->openingIssue($row, 'variant_parent', "Variant SKU {$row->variantSku} does not belong to {$row->sku}.");
                }
            }
        } elseif ($product !== null && $product->variants()->where('is_active', true)->exists()) {
            $issues[] = $this->openingIssue($row, 'variant_required', "SKU {$row->sku} has variants. Select a Variant SKU.");
        }

        if ($serialized === true) {
            if ($row->serialNumber === '') {
                $issues[] = $this->openingIssue($row, 'serial_required', 'Serial Number is required when the SKU is serialized.');
            }
            if ($row->quantity !== null && $row->quantity !== 1) {
                $issues[] = $this->openingIssue($row, 'serial_qty', 'Serialized opening rows must have Quantity 1.');
            }
            if ($row->serialNumber !== '') {
                if (isset($serials[$row->serialNumber])) {
                    $issues[] = $this->openingIssue($row, 'serial_duplicate', "Serial {$row->serialNumber} is duplicated on the workbook. Import does not auto-correct.");
                    $issues[] = new OpeningInventoryIssue(
                        sheet: OpeningInventoryTemplate::SHEET_OPENING,
                        rowNumber: $serials[$row->serialNumber],
                        code: 'serial_duplicate',
                        message: "Serial {$row->serialNumber} is duplicated on the workbook. Import does not auto-correct.",
                    );
                }
                $serials[$row->serialNumber] = $row->rowNumber;

                if (InventorySerial::query()->where('serial_number', $row->serialNumber)->exists()) {
                    $issues[] = $this->openingIssue($row, 'serial_exists', "Serial {$row->serialNumber} already exists in Desk inventory.");
                }
            }
        } elseif ($serialized === false) {
            if ($row->serialNumber !== '') {
                $issues[] = $this->openingIssue($row, 'serial_forbidden', 'Non-serialized opening rows must leave Serial Number blank.');
            }
            if ($row->stockStatus === InventorySerialStatus::Damaged) {
                $issues[] = $this->openingIssue(
                    $row,
                    'qty_damaged',
                    'Damaged non-serialized quantity is not supported. Desk has no damaged_qty column; do not invent one.',
                );
            }
            $identity = $row->appliedIdentity();
            if (isset($qtyIdentities[$identity])) {
                $issues[] = $this->openingIssue($row, 'qty_duplicate', 'Identical non-serialized opening rows would double-count. Distinguish them with Remarks or split the count.');
            }
            $qtyIdentities[$identity] = $row->rowNumber;

            if (InventoryOpeningImportRow::query()->where('applied_identity', $identity)->exists()) {
                $issues[] = $this->openingIssue($row, 'qty_replay', 'This non-serialized opening row was already applied. Import will not add the quantity again.');
            }
        }
    }

    /**
     * @return array{skus: int, variants: int}
     */
    private function applySkuMaster(OpeningInventoryWorkbook $workbook): array
    {
        $createdSkus = 0;
        $createdVariants = 0;

        foreach ($workbook->skuRows as $skuRow) {
            $product = InventoryProduct::query()->where('sku', $skuRow->sku)->first();
            if ($product === null) {
                $product = InventoryProduct::query()->create([
                    'sku' => $skuRow->sku,
                    'name' => $skuRow->name,
                    'hsn_code' => $skuRow->hsn !== '' ? $skuRow->hsn : null,
                    'gst_percentage' => $skuRow->gstPercentage,
                    'unit_price' => $skuRow->unitPrice,
                    'unit_cost' => $skuRow->unitCost,
                    'is_serialized' => (bool) $skuRow->serialized,
                    'is_active' => $skuRow->active ?? true,
                ]);
                $createdSkus++;
            }

            if ($skuRow->variantSku === '') {
                continue;
            }

            $variant = InventoryProductVariant::query()->where('sku', $skuRow->variantSku)->first();
            if ($variant === null) {
                $product->variants()->create([
                    'sku' => $skuRow->variantSku,
                    'name' => $skuRow->name !== '' ? $skuRow->name : $skuRow->variantSku,
                    'unit_price' => $skuRow->unitPrice,
                    'is_active' => $skuRow->active ?? true,
                ]);
                $createdVariants++;
            }
        }

        return ['skus' => $createdSkus, 'variants' => $createdVariants];
    }

    private function resolveProduct(OpeningInventoryCountRow $row): InventoryProduct
    {
        return InventoryProduct::query()->where('sku', $row->sku)->firstOrFail();
    }

    private function resolveVariant(OpeningInventoryCountRow $row, InventoryProduct $product): ?InventoryProductVariant
    {
        if ($row->variantSku === '') {
            return null;
        }

        return InventoryProductVariant::query()
            ->where('sku', $row->variantSku)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function requireBranch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->where('code', $code)->firstOrFail();
    }

    private function openingNotes(OpeningInventoryCountRow $row): string
    {
        $parts = array_filter([
            'Opening inventory',
            $row->openingDate?->toDateString(),
            $row->condition !== null ? 'condition='.$row->condition->label() : null,
            $row->countedBy !== '' ? 'counted_by='.$row->countedBy : null,
            $row->remarks !== '' ? $row->remarks : null,
        ]);

        return implode('; ', $parts);
    }

    private function markRowApplied(
        InventoryOpeningImportBatch $batch,
        OpeningInventoryCountRow $row,
        InventoryProduct $product,
        ?int $serialId,
        ?int $movementId,
    ): void {
        InventoryOpeningImportRow::query()
            ->where('batch_id', $batch->id)
            ->where('sheet', OpeningInventoryTemplate::SHEET_OPENING)
            ->where('row_number', $row->rowNumber)
            ->update([
                'status' => 'applied',
                'applied_identity' => $row->appliedIdentity(),
                'product_id' => $product->id,
                'serial_id' => $serialId,
                'movement_id' => $movementId,
            ]);
    }

    /**
     * @param  list<OpeningInventoryIssue>  $issues
     */
    private function resultFromBatch(
        InventoryOpeningImportBatch $batch,
        array $issues,
        bool $alreadyApplied,
        ?int $rowsApplied = null,
    ): OpeningInventoryImportResult {
        return new OpeningInventoryImportResult(
            batch: $batch,
            canApply: $alreadyApplied || $batch->status === InventoryOpeningImportStatus::Previewed,
            alreadyApplied: $alreadyApplied,
            openingRows: (int) ($batch->summary['opening_rows'] ?? 0),
            validRows: (int) $batch->rows_valid,
            invalidRows: (int) $batch->rows_invalid,
            skuRows: (int) ($batch->summary['sku_rows'] ?? 0),
            skusCreated: (int) $batch->sku_created_count,
            variantsCreated: (int) $batch->variant_created_count,
            rowsApplied: $rowsApplied ?? (int) $batch->rows_applied,
            issues: $issues,
            reconciliation: OpeningInventoryReconciliation::fromArray($batch->summary['reconciliation'] ?? null),
        );
    }

    private function skuIssue(OpeningInventorySkuRow $row, string $code, string $message): OpeningInventoryIssue
    {
        return new OpeningInventoryIssue(
            sheet: OpeningInventoryTemplate::SHEET_SKU,
            rowNumber: $row->rowNumber,
            code: $code,
            message: $message,
        );
    }

    private function openingIssue(OpeningInventoryCountRow $row, string $code, string $message): OpeningInventoryIssue
    {
        return new OpeningInventoryIssue(
            sheet: OpeningInventoryTemplate::SHEET_OPENING,
            rowNumber: $row->rowNumber,
            code: $code,
            message: $message,
        );
    }
}
