<?php

namespace Tests\Feature\Pos;

use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryPosSerialExchange;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleSerialExchangeService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class PosSaleSerialExchangeTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private InventoryBranch $branch;

    private InventoryBranch $otherBranch;

    private PosSaleService $sales;

    private PosSaleSerialExchangeService $exchanges;

    private StatutoryInvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->disableRequestForgeryProtection();

        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.location_series.enabled' => false,
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->viewer = User::factory()->create(['is_active' => true]);
        $this->viewer->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        $this->otherBranch = InventoryBranch::query()->create([
            'code' => 'MUMBAI',
            'name' => 'Mumbai',
            'is_active' => true,
        ]);

        $this->sales = app(PosSaleService::class);
        $this->exchanges = app(PosSaleSerialExchangeService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
    }

    public function test_successful_exchange_swaps_serials_and_records_audit_movements(): void
    {
        [$sale, $oldSerial, $newSerial] = $this->completedSaleWithSerials('SN-OLD-1', 'SN-NEW-1');
        $totalsBefore = $this->saleMoneySnapshot($sale);
        $journalCountBefore = $this->journalCountFor($sale);

        $exchange = $this->exchanges->exchange(
            sale: $sale,
            fromSerialNumber: 'SN-OLD-1',
            toSerialNumber: 'SN-NEW-1',
            actor: $this->admin,
            reason: 'Defective unit swap',
            idempotencyKey: 'ex-'.Str::uuid(),
        );

        $sale->refresh();
        $oldSerial->refresh();
        $newSerial->refresh();

        $this->assertSame('SN-NEW-1', $sale->serials()->first()?->serial?->serial_number);
        $this->assertSame(InventorySerialStatus::Available, $oldSerial->status);
        $this->assertSame(InventorySerialStatus::Sold, $newSerial->status);
        $this->assertSame(1, InventoryPosSerialExchange::query()->count());
        $this->assertSame(2, InventoryMovement::query()->where('sale_id', $sale->id)->where('type', InventoryMovementType::SerialExchange)->count());
        $movementNote = (string) InventoryMovement::query()
            ->where('sale_id', $sale->id)
            ->where('type', InventoryMovementType::SerialExchange)
            ->value('notes');
        $this->assertStringContainsString('SN-OLD-1', $movementNote);
        $this->assertStringContainsString('SN-NEW-1', $movementNote);
        $this->assertSame($totalsBefore, $this->saleMoneySnapshot($sale));
        $this->assertSame($journalCountBefore, $this->journalCountFor($sale));
    }

    public function test_unavailable_replacement_serial_is_rejected_without_mutation(): void
    {
        [$sale, $oldSerial] = $this->completedSaleWithSerials('SN-OLD-2', 'SN-NEW-2');
        InventorySerial::query()->where('serial_number', 'SN-NEW-2')->update(['status' => InventorySerialStatus::Sold]);

        $this->expectException(ValidationException::class);

        try {
            $this->exchanges->exchange(
                sale: $sale,
                fromSerialNumber: 'SN-OLD-2',
                toSerialNumber: 'SN-NEW-2',
                actor: $this->admin,
                reason: 'Should fail',
                idempotencyKey: 'ex-fail-1',
            );
        } finally {
            $sale->refresh();
            $oldSerial->refresh();
            $this->assertSame('SN-OLD-2', $sale->serials()->first()?->serial?->serial_number);
            $this->assertSame(InventorySerialStatus::Sold, $oldSerial->status);
            $this->assertSame(0, InventoryPosSerialExchange::query()->count());
        }
    }

    public function test_replacement_serial_already_sold_on_another_sale_is_blocked(): void
    {
        [$saleA] = $this->completedSaleWithSerials('SN-A-1', 'SN-POOL-1');
        $this->completedSaleWithSerials('SN-B-1', 'SN-POOL-2');

        $this->expectException(ValidationException::class);

        $this->exchanges->exchange(
            sale: $saleA,
            fromSerialNumber: 'SN-A-1',
            toSerialNumber: 'SN-B-1',
            actor: $this->admin,
            reason: 'Attempt to steal serial from other sale',
            idempotencyKey: 'ex-dup-1',
        );
    }

    public function test_wrong_product_replacement_is_blocked(): void
    {
        [$sale] = $this->completedSaleWithSerials('SN-OLD-3', 'SN-NEW-3');
        $otherProduct = InventoryProduct::query()->create([
            'sku' => 'SKU-OTHER',
            'name' => 'Other product',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 50,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($otherProduct, $this->branch, ['SN-OTHER-1'], $this->admin);

        $this->expectException(ValidationException::class);

        $this->exchanges->exchange(
            sale: $sale,
            fromSerialNumber: 'SN-OLD-3',
            toSerialNumber: 'SN-OTHER-1',
            actor: $this->admin,
            reason: 'Wrong SKU',
            idempotencyKey: 'ex-wrong-product',
        );
    }

    public function test_replacement_serial_at_wrong_branch_is_blocked(): void
    {
        [$sale] = $this->completedSaleWithSerials('SN-OLD-4', 'SN-NEW-4');
        $product = InventoryProduct::query()->where('sku', 'like', 'SKU-XCH-%')->firstOrFail();
        app(InventoryStockService::class)->stockInSerialized($product, $this->otherBranch, ['SN-REMOTE-1'], $this->admin);

        $this->expectException(ValidationException::class);

        $this->exchanges->exchange(
            sale: $sale,
            fromSerialNumber: 'SN-OLD-4',
            toSerialNumber: 'SN-REMOTE-1',
            actor: $this->admin,
            reason: 'Wrong branch serial',
            idempotencyKey: 'ex-wrong-branch',
        );
    }

    public function test_from_serial_not_on_sale_is_blocked(): void
    {
        [$sale] = $this->completedSaleWithSerials('SN-OLD-5', 'SN-NEW-5');

        $this->expectException(ValidationException::class);

        $this->exchanges->exchange(
            sale: $sale,
            fromSerialNumber: 'SN-NOT-ON-SALE',
            toSerialNumber: 'SN-NEW-5',
            actor: $this->admin,
            reason: 'Bad from serial',
            idempotencyKey: 'ex-bad-from',
        );
    }

    public function test_http_exchange_requires_authorization(): void
    {
        [$sale] = $this->completedSaleWithSerials('SN-OLD-HTTP', 'SN-NEW-HTTP');

        $this->actingAs($this->viewer)
            ->post(route('pos.sales.serial-exchange', $sale), [
                'from_serial' => 'SN-OLD-HTTP',
                'to_serial' => 'SN-NEW-HTTP',
                'reason' => 'Unauthorized',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertForbidden();
    }

    public function test_idempotent_retry_returns_same_exchange_without_double_mutation(): void
    {
        [$sale] = $this->completedSaleWithSerials('SN-OLD-RETRY', 'SN-NEW-RETRY');
        $key = 'ex-retry-'.Str::uuid();

        $first = $this->exchanges->exchange(
            sale: $sale,
            fromSerialNumber: 'SN-OLD-RETRY',
            toSerialNumber: 'SN-NEW-RETRY',
            actor: $this->admin,
            reason: 'Retry test',
            idempotencyKey: $key,
        );

        $second = $this->exchanges->exchange(
            sale: $sale->fresh(),
            fromSerialNumber: 'SN-OLD-RETRY',
            toSerialNumber: 'SN-NEW-RETRY',
            actor: $this->admin,
            reason: 'Retry test',
            idempotencyKey: $key,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, InventoryPosSerialExchange::query()->count());
        $this->assertSame(2, InventoryMovement::query()->where('type', InventoryMovementType::SerialExchange)->count());
    }

    public function test_second_sale_cannot_claim_same_replacement_serial(): void
    {
        [$saleA] = $this->completedSaleWithSerials('SN-COMP-A', 'SN-REPL-POOL');
        [$saleB] = $this->completedSaleWithSerials('SN-COMP-B', 'SN-REPL-ALT');

        $this->exchanges->exchange(
            sale: $saleA,
            fromSerialNumber: 'SN-COMP-A',
            toSerialNumber: 'SN-REPL-POOL',
            actor: $this->admin,
            reason: 'First claim',
            idempotencyKey: 'ex-race-1',
        );

        $this->expectException(ValidationException::class);

        $this->exchanges->exchange(
            sale: $saleB,
            fromSerialNumber: 'SN-COMP-B',
            toSerialNumber: 'SN-REPL-POOL',
            actor: $this->admin,
            reason: 'Second claim should fail',
            idempotencyKey: 'ex-race-2',
        );
    }

    public function test_issued_statutory_invoice_blocks_exchange(): void
    {
        config([
            'statutory_invoices.location_series.enabled' => true,
        ]);
        $this->configureLocationSellerIdentity();

        [$sale] = $this->completedSaleWithSerials('SN-OLD-GST', 'SN-NEW-GST', issueStatutory: true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('GST invoice record exists');

        $this->exchanges->exchange(
            sale: $sale,
            fromSerialNumber: 'SN-OLD-GST',
            toSerialNumber: 'SN-NEW-GST',
            actor: $this->admin,
            reason: 'Should be blocked',
            idempotencyKey: 'ex-gst-block',
        );
    }

    public function test_admin_can_exchange_via_http(): void
    {
        [$sale] = $this->completedSaleWithSerials('SN-OLD-WEB', 'SN-NEW-WEB');

        $this->actingAs($this->admin)
            ->post(route('pos.sales.serial-exchange', $sale), [
                'from_serial' => 'SN-OLD-WEB',
                'to_serial' => 'SN-NEW-WEB',
                'reason' => 'Customer swap',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('pos.sales.show', $sale));

        $sale->refresh();
        $this->assertSame('SN-NEW-WEB', $sale->serials()->first()?->serial?->serial_number);
    }

    /**
     * @return array{0: InventorySale, 1: InventorySerial, 2: InventorySerial}
     */
    private function completedSaleWithSerials(string $soldSerial, string $replacementSerial, bool $issueStatutory = false): array
    {
        $marker = preg_replace('/[^A-Z0-9-]/', '', strtoupper($soldSerial)) ?: 'X';
        $product = InventoryProduct::query()->create([
            'sku' => 'SKU-XCH-'.$marker,
            'name' => 'Exchange product '.$marker,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $stock = app(InventoryStockService::class);
        $stock->stockInSerialized($product, $this->branch, [$soldSerial, $replacementSerial], $this->admin);

        $phoneSuffix = str_pad((string) abs(crc32($marker)), 10, '0', STR_PAD_LEFT);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Buyer '.$marker, 'phone' => '9'.$phoneSuffix],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [$soldSerial],
            ]],
            paymentMethod: 'Cash',
            actor: $this->admin,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );

        if ($issueStatutory) {
            $invoice = $this->invoices->issueFromPosSale($sale, $this->admin);
            $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->status);
            $this->assertSame(1, StatutoryInvoice::query()->count());
        }

        $oldSerial = InventorySerial::query()->where('serial_number', $soldSerial)->firstOrFail();
        $newSerial = InventorySerial::query()->where('serial_number', $replacementSerial)->firstOrFail();

        return [$sale->fresh(['serials.serial']), $oldSerial, $newSerial];
    }

    /**
     * @return array{subtotal: string, discount: string, tax: string, total: string}
     */
    private function saleMoneySnapshot(InventorySale $sale): array
    {
        $sale->refresh();

        return [
            'subtotal' => number_format((float) $sale->subtotal, 2, '.', ''),
            'discount' => number_format((float) $sale->discount, 2, '.', ''),
            'tax' => number_format((float) $sale->tax, 2, '.', ''),
            'total' => number_format((float) $sale->total, 2, '.', ''),
        ];
    }

    private function journalCountFor(InventorySale $sale): int
    {
        return (int) \DB::table('finance_journals')
            ->where('source_type', 'pos_sale')
            ->where('source_id', $sale->id)
            ->count();
    }
}
