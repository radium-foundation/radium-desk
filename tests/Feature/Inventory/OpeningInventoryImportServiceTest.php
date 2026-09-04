<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryOpeningImportStatus;
use App\Enums\InventorySerialCondition;
use App\Enums\InventorySerialStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryOpeningImportBatch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\User;
use App\Services\Inventory\Opening\OpeningInventoryImportService;
use App\Services\Inventory\Opening\OpeningInventoryTemplate;
use App\Services\Inventory\Opening\OpeningInventoryWorkbookReader;
use App\Services\Inventory\Opening\OpeningInventoryWorkbookWriter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OpeningInventoryImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private OpeningInventoryImportService $imports;

    private OpeningInventoryWorkbookWriter $writer;

    private User $actor;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->imports = app(OpeningInventoryImportService::class);
        $this->writer = app(OpeningInventoryWorkbookWriter::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-WH',
            'name' => 'Delhi Warehouse',
            'is_active' => true,
        ]);
    }

    public function test_empty_agreed_template_headers_match_the_mapping(): void
    {
        $path = storage_path('app/private/inventory-opening/rd-fresh-01-opening-inventory-template.xlsx');
        if (! is_file($path)) {
            $this->markTestSkipped('Agreed empty template is not in this environment.');
        }

        $workbook = app(OpeningInventoryWorkbookReader::class)->read($path);

        $this->assertSame([], $workbook->openingRows);
        $this->assertSame([], $workbook->skuRows);
        $this->assertContains('DELHI-WH', array_map(fn ($row) => $row->code, $workbook->branchRows));
        $this->assertContains('DELHI-RETAIL', array_map(fn ($row) => $row->code, $workbook->branchRows));
        $this->assertContains('BIHAR', array_map(fn ($row) => $row->code, $workbook->branchRows));
        $this->assertContains('MUMBAI', array_map(fn ($row) => $row->code, $workbook->branchRows));
        $this->assertSame(OpeningInventoryTemplate::OPENING_HEADERS[0], 'Opening Date');
        $this->assertSame(OpeningInventoryTemplate::SKU_HEADERS[0], 'SKU');
    }

    public function test_preview_does_not_create_stock_or_skus(): void
    {
        $path = $this->workbookPath([$this->serializedOpeningRow()], [$this->skuMasterRow()], [$this->branchRow()]);

        $result = $this->imports->preview($path, $this->actor);

        $this->assertTrue($result->canApply);
        $this->assertSame(1, $result->openingRows);
        $this->assertSame(0, InventoryProduct::query()->count());
        $this->assertSame(0, InventorySerial::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(InventoryOpeningImportStatus::Previewed, $result->batch->status);
    }

    public function test_apply_creates_sku_serial_condition_and_opening_movement(): void
    {
        $path = $this->workbookPath([$this->serializedOpeningRow()], [$this->skuMasterRow()], [$this->branchRow()]);

        $result = $this->imports->apply($path, $this->actor);

        $this->assertFalse($result->alreadyApplied);
        $this->assertSame(1, $result->rowsApplied);
        $this->assertSame(1, $result->skusCreated);
        $this->assertSame(InventoryOpeningImportStatus::Applied, $result->batch->status);

        $product = InventoryProduct::query()->where('sku', 'PMTMFS110Z')->first();
        $this->assertNotNull($product);
        $this->assertTrue($product->is_serialized);
        $this->assertSame('18.00', (string) $product->gst_percentage);
        $this->assertSame('2117.80', (string) $product->unit_price);

        $serial = InventorySerial::query()->where('serial_number', 'SN-OPEN-1')->first();
        $this->assertNotNull($serial);
        $this->assertSame(InventorySerialStatus::Available, $serial->status);
        $this->assertSame(InventorySerialCondition::New, $serial->condition);
        $this->assertSame($this->branch->id, $serial->branch_id);
        $this->assertSame(1, (int) $product->balances()->where('branch_id', $this->branch->id)->value('available_qty'));

        $movement = InventoryMovement::query()->where('type', InventoryMovementType::Opening)->first();
        $this->assertNotNull($movement);
        $this->assertSame($result->batch->id, $movement->opening_import_batch_id);
        $this->assertSame('2026-09-04', $movement->occurred_at?->toDateString());
        $this->assertStringContainsString('counted_by=A. Sharma', (string) $movement->notes);
    }

    public function test_apply_is_idempotent_for_the_same_workbook(): void
    {
        $path = $this->workbookPath([$this->serializedOpeningRow()], [$this->skuMasterRow()], [$this->branchRow()]);

        $first = $this->imports->apply($path, $this->actor);
        $second = $this->imports->apply($path, $this->actor);

        $this->assertTrue($second->alreadyApplied);
        $this->assertSame($first->batch->id, $second->batch->id);
        $this->assertSame(1, InventorySerial::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', InventoryMovementType::Opening)->count());
        $this->assertSame(1, InventoryOpeningImportBatch::query()->count());
    }

    public function test_apply_rolls_back_when_any_row_is_blocked(): void
    {
        $path = $this->workbookPath(
            [$this->serializedOpeningRow(['Serial Number' => ''])],
            [$this->skuMasterRow()],
            [$this->branchRow()],
        );

        try {
            $this->imports->apply($path, $this->actor);
            $this->fail('Blocked workbook should not apply.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('blocked', strtolower(implode(' ', $exception->errors()['workbook'] ?? [])));
        }

        $this->assertSame(0, InventoryProduct::query()->count());
        $this->assertSame(0, InventorySerial::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_missing_desk_branch_is_a_blocking_issue_and_is_not_created(): void
    {
        $this->branch->delete();
        $path = $this->workbookPath([$this->serializedOpeningRow()], [$this->skuMasterRow()], [$this->branchRow()]);

        $result = $this->imports->preview($path, $this->actor);

        $this->assertFalse($result->canApply);
        $this->assertTrue(collect($result->issues)->contains(fn ($issue) => $issue->code === 'branch_missing'));
        $this->assertSame(0, InventoryBranch::query()->count());
    }

    public function test_duplicate_serial_in_workbook_is_not_auto_corrected(): void
    {
        $path = $this->workbookPath(
            [
                $this->serializedOpeningRow(),
                $this->serializedOpeningRow(['SKU' => 'PMTMFS110Z']),
            ],
            [$this->skuMasterRow()],
            [$this->branchRow()],
        );

        $result = $this->imports->preview($path, $this->actor);

        $this->assertFalse($result->canApply);
        $this->assertGreaterThanOrEqual(2, collect($result->issues)->where('code', 'serial_duplicate')->count());
    }

    public function test_sold_stock_status_is_rejected(): void
    {
        $path = $this->workbookPath(
            [$this->serializedOpeningRow(['Stock Status' => 'Sold'])],
            [$this->skuMasterRow()],
            [$this->branchRow()],
        );

        $result = $this->imports->preview($path, $this->actor);

        $this->assertFalse($result->canApply);
        $this->assertTrue(collect($result->issues)->contains(fn ($issue) => $issue->code === 'stock_status_invalid'));
    }

    public function test_damaged_serial_does_not_increase_available_qty(): void
    {
        $path = $this->workbookPath(
            [$this->serializedOpeningRow(['Stock Status' => 'Damaged', 'Condition' => 'Used'])],
            [$this->skuMasterRow()],
            [$this->branchRow()],
        );

        $this->imports->apply($path, $this->actor);

        $serial = InventorySerial::query()->where('serial_number', 'SN-OPEN-1')->first();
        $this->assertSame(InventorySerialStatus::Damaged, $serial?->status);
        $this->assertSame(InventorySerialCondition::Used, $serial?->condition);
        $this->assertSame(0, (int) InventoryProduct::query()->first()?->balances()->value('available_qty'));
    }

    public function test_damaged_quantity_row_is_rejected_without_inventing_a_column(): void
    {
        $path = $this->workbookPath(
            [$this->quantityOpeningRow(['Stock Status' => 'Damaged'])],
            [$this->skuMasterRow(['SKU' => 'CABLE-1M', 'Serialized' => 'N', 'Product Name' => 'OTG cable'])],
            [$this->branchRow()],
        );

        $result = $this->imports->preview($path, $this->actor);

        $this->assertFalse($result->canApply);
        $this->assertTrue(collect($result->issues)->contains(fn ($issue) => $issue->code === 'qty_damaged'));
        $this->assertSame(0, InventoryProduct::query()->count());
    }

    public function test_quantity_opening_increments_balance_and_cannot_replay_the_same_identity(): void
    {
        $firstPath = $this->workbookPath(
            [$this->quantityOpeningRow()],
            [$this->skuMasterRow(['SKU' => 'CABLE-1M', 'Serialized' => 'N', 'Product Name' => 'OTG cable'])],
            [$this->branchRow()],
        );
        $this->imports->apply($firstPath, $this->actor);

        $secondPath = $this->workbookPath(
            [$this->quantityOpeningRow(['Counted By' => 'Different counter'])],
            [$this->skuMasterRow(['SKU' => 'CABLE-1M', 'Serialized' => 'N', 'Product Name' => 'OTG cable'])],
            [$this->branchRow()],
        );
        $replay = $this->imports->preview($secondPath, $this->actor);

        $this->assertSame(5, (int) InventoryProduct::query()->where('sku', 'CABLE-1M')->first()?->balances()->value('available_qty'));
        $this->assertFalse($replay->canApply);
        $this->assertTrue(collect($replay->issues)->contains(fn ($issue) => $issue->code === 'qty_replay'));
    }

    public function test_existing_sku_is_not_rewritten(): void
    {
        InventoryProduct::query()->create([
            'sku' => 'PMTMFS110Z',
            'name' => 'Existing name',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $path = $this->workbookPath([$this->serializedOpeningRow()], [$this->skuMasterRow()], [$this->branchRow()]);
        $this->imports->apply($path, $this->actor);

        $product = InventoryProduct::query()->where('sku', 'PMTMFS110Z')->first();
        $this->assertSame('Existing name', $product?->name);
        $this->assertSame('1000.00', (string) $product?->unit_price);
        $this->assertSame(1, InventoryProduct::query()->count());
    }

    public function test_preview_reconciliation_counts_valid_rows_only(): void
    {
        $path = $this->workbookPath(
            [
                $this->serializedOpeningRow(),
                $this->serializedOpeningRow([
                    'Serial Number' => 'SN-OPEN-DMG',
                    'Stock Status' => 'Damaged',
                    'Condition' => 'Used',
                ]),
                $this->quantityOpeningRow(),
            ],
            [
                $this->skuMasterRow(),
                $this->skuMasterRow(['SKU' => 'CABLE-1M', 'Serialized' => 'N', 'Product Name' => 'OTG cable']),
            ],
            [$this->branchRow()],
        );

        $result = $this->imports->preview($path, $this->actor);

        $this->assertTrue($result->canApply);
        $this->assertSame(1, $result->reconciliation->availableSerials);
        $this->assertSame(1, $result->reconciliation->damagedSerials);
        $this->assertSame(5, $result->reconciliation->quantityUnits);
        $this->assertSame('DELHI-WH', $result->reconciliation->byBranch[0]['branch']);
        $this->assertSame(1, $result->reconciliation->byBranch[0]['available_serials']);
        $this->assertSame(5, $result->reconciliation->byBranch[0]['quantity_units']);
    }

    public function test_admin_style_same_serial_on_two_skus_is_rejected(): void
    {
        $path = $this->workbookPath(
            [
                $this->serializedOpeningRow(['SKU' => 'PMTMFS110Z', 'Serial Number' => 'SHARED-1']),
                $this->serializedOpeningRow(['SKU' => 'PMTMFS100Z', 'Serial Number' => 'SHARED-1', 'Product Name' => 'Mantra MFS 100']),
            ],
            [
                $this->skuMasterRow(),
                $this->skuMasterRow(['SKU' => 'PMTMFS100Z', 'Product Name' => 'Mantra MFS 100']),
            ],
            [$this->branchRow()],
        );

        $result = $this->imports->preview($path, $this->actor);

        $this->assertFalse($result->canApply);
        $this->assertGreaterThanOrEqual(2, collect($result->issues)->where('code', 'serial_duplicate')->count());
        $this->assertSame(0, $result->reconciliation->availableSerials);
    }

    public function test_admin_numeric_product_id_is_not_accepted_as_sku(): void
    {
        $path = $this->workbookPath(
            [$this->serializedOpeningRow(['SKU' => '946'])],
            [$this->skuMasterRow()],
            [$this->branchRow()],
        );

        $result = $this->imports->preview($path, $this->actor);

        $this->assertFalse($result->canApply);
        $this->assertTrue(collect($result->issues)->contains(fn ($issue) => $issue->code === 'sku_unknown'));
    }

    public function test_blank_serial_unit_cost_is_not_copied_from_catalog(): void
    {
        InventoryProduct::query()->create([
            'sku' => 'PMTMFS110Z',
            'name' => 'Existing name',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'unit_cost' => '999.00',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $path = $this->workbookPath(
            [$this->serializedOpeningRow(['Unit Cost' => ''])],
            [$this->skuMasterRow()],
            [$this->branchRow()],
        );
        $this->imports->apply($path, $this->actor);

        $serial = InventorySerial::query()->where('serial_number', 'SN-OPEN-1')->first();
        $this->assertNull($serial?->unit_cost);
    }

    public function test_desk_variant_on_another_parent_is_blocking(): void
    {
        $parent = InventoryProduct::query()->create([
            'sku' => 'PMTMFS110Z',
            'name' => 'Mantra MFS 110',
            'gst_percentage' => 18,
            'unit_price' => 2117.80,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $other = InventoryProduct::query()->create([
            'sku' => 'PMTMFS100Z',
            'name' => 'Mantra MFS 100',
            'gst_percentage' => 18,
            'unit_price' => 1800,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $other->variants()->create([
            'sku' => 'PMTMFS100Z-BLK',
            'name' => 'Black',
            'is_active' => true,
        ]);

        $path = $this->workbookPath(
            [$this->serializedOpeningRow(['Variant SKU' => 'PMTMFS100Z-BLK'])],
            [$this->skuMasterRow()],
            [$this->branchRow()],
        );
        $result = $this->imports->preview($path, $this->actor);

        $this->assertFalse($result->canApply);
        $this->assertTrue(collect($result->issues)->contains(fn ($issue) => $issue->code === 'variant_parent'));
        $this->assertSame($parent->id, InventoryProduct::query()->where('sku', 'PMTMFS110Z')->value('id'));
    }

    public function test_serialized_mismatch_on_existing_sku_blocks_import(): void
    {
        InventoryProduct::query()->create([
            'sku' => 'PMTMFS110Z',
            'name' => 'Existing',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $path = $this->workbookPath([$this->serializedOpeningRow()], [$this->skuMasterRow()], [$this->branchRow()]);
        $result = $this->imports->preview($path, $this->actor);

        $this->assertFalse($result->canApply);
        $this->assertTrue(collect($result->issues)->contains(fn ($issue) => $issue->code === 'sku_serialized_mismatch'));
    }

    /**
     * @param  array<string, string|int>  $overrides
     * @return list<string|int|null>
     */
    private function serializedOpeningRow(array $overrides = []): array
    {
        $row = array_merge([
            'Opening Date' => '2026-09-04',
            'Branch Code' => 'DELHI-WH',
            'Location Type' => 'Warehouse',
            'SKU' => 'PMTMFS110Z',
            'Variant SKU' => '',
            'Product Name' => 'Mantra MFS 110',
            'Serialized' => 'Y',
            'Condition' => 'New',
            'Stock Status' => 'Available',
            'Serial Number' => 'SN-OPEN-1',
            'Quantity' => 1,
            'Unit Cost' => '1800.00',
            'Selling Price' => '',
            'GST %' => '18',
            'HSN' => '84716050',
            'Counted By' => 'A. Sharma',
            'Remarks' => 'Box 1',
            'Row Issues' => '',
        ], $overrides);

        return array_values($row);
    }

    /**
     * @param  array<string, string|int>  $overrides
     * @return list<string|int|null>
     */
    private function quantityOpeningRow(array $overrides = []): array
    {
        return $this->serializedOpeningRow(array_merge([
            'SKU' => 'CABLE-1M',
            'Product Name' => 'OTG cable',
            'Serialized' => 'N',
            'Serial Number' => '',
            'Quantity' => 5,
            'Condition' => 'New',
        ], $overrides));
    }

    /**
     * @param  array<string, string|int>  $overrides
     * @return list<string|int|null>
     */
    private function skuMasterRow(array $overrides = []): array
    {
        $row = array_merge([
            'SKU' => 'PMTMFS110Z',
            'Product Name' => 'Mantra MFS 110',
            'Variant SKU' => '',
            'Serialized' => 'Y',
            'HSN' => '84716050',
            'GST %' => '18',
            'Default Selling Price' => '2117.80',
            'Default Unit Cost' => '1800.00',
            'Active' => 'Y',
            'Remarks' => '',
        ], $overrides);

        return array_values($row);
    }

    /**
     * @return list<string|int|null>
     */
    private function branchRow(): array
    {
        return ['DELHI-WH', 'Delhi Warehouse', 'Warehouse', '', 'Delhi', 'New Delhi', '', 'Y', ''];
    }

    /**
     * @param  list<list<string|int|null>>  $opening
     * @param  list<list<string|int|null>>  $skus
     * @param  list<list<string|int|null>>  $branches
     */
    private function workbookPath(array $opening, array $skus, array $branches): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opening').'.xlsx';
        $this->writer->write($path, $opening, $skus, $branches);

        return $path;
    }
}
