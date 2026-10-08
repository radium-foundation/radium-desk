<?php

namespace Tests\Feature\Inventory;

use App\Enums\InterBranchTransactionStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\InterBranchTransaction;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InterBranchTransferService;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InterBranchTransferTest extends TestCase
{
    use RefreshDatabase;

    private InterBranchTransferService $interBranch;

    private InventoryStockService $stock;

    private User $actor;

    private InventoryBranch $delhi;

    private InventoryBranch $mumbai;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 12:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->interBranch = app(InterBranchTransferService::class);
        $this->stock = app(InventoryStockService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->delhi = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);
        $this->mumbai = InventoryBranch::query()->create([
            'code' => 'MUMBAI',
            'name' => 'Mumbai',
            'gstin' => '27AAICP1128M1Z7',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_delhi_to_mumbai_serialized_journey_with_igst_invoice(): void
    {
        $product = $this->serializedProduct();
        $this->stock->stockInSerialized($product, $this->delhi, ['IBT-001'], $this->actor);

        $issued = $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['IBT-001'],
            ]],
            actor: $this->actor,
            idempotencyKey: 'ibt-journey-1',
        );

        $this->assertSame(InterBranchTransactionStatus::Issued, $issued->status);
        $this->assertNotNull($issued->statutory_invoice_id);
        $this->assertNotNull($issued->inventory_transfer_id);
        $this->assertNull(InventorySale::query()->first());

        $invoice = $issued->statutoryInvoice;
        $this->assertSame(StatutoryInvoiceChannel::DeskInventory, $invoice->channel);
        $this->assertSame(StatutoryInvoiceSourceType::InterBranchTransfer->value, $invoice->source_type);
        $this->assertSame((string) $issued->id, $invoice->source_id);
        $this->assertNull($invoice->inventory_sale_id);
        $this->assertSame($this->configuredSellerGstin('delhi'), $invoice->seller_gstin);
        $this->assertSame('27AAICP1128M1Z7', $invoice->buyer_gstin);
        $this->assertSame('Maharashtra', $invoice->place_of_supply_state);
        $this->assertGreaterThan(0, (float) $invoice->igst);

        $serial = InventorySerial::query()->where('serial_number', 'IBT-001')->firstOrFail();
        $this->assertSame(InventorySerialStatus::Reserved, $serial->status);
        $this->assertSame($this->delhi->id, $serial->branch_id);

        $dispatched = $this->interBranch->dispatch($issued, $this->actor, [
            'transporter' => 'Test Carrier',
            'eway_bill_reference' => 'EWB-REF-123',
        ]);
        $this->assertSame(InterBranchTransactionStatus::InTransit, $dispatched->status);
        $serial->refresh();
        $this->assertSame(InventorySerialStatus::InTransit, $serial->status);

        $completed = $this->interBranch->receive($dispatched, $this->actor);
        $this->assertSame(InterBranchTransactionStatus::Completed, $completed->status);
        $serial->refresh();
        $this->assertSame(InventorySerialStatus::Available, $serial->status);
        $this->assertSame($this->mumbai->id, $serial->branch_id);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->mumbai,
            customer: ['name' => 'Final Customer', 'phone' => '9000000101'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['IBT-001'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );

        $this->assertSame(InventorySerialStatus::Sold, $serial->fresh()->status);
        $this->assertNotNull($sale->id);
    }

    public function test_mumbai_to_delhi_serialized_journey(): void
    {
        $product = $this->serializedProduct('SKU-MH-DL');
        $this->stock->stockInSerialized($product, $this->mumbai, ['MH-DL-1'], $this->actor);

        $issued = $this->interBranch->issue(
            from: $this->mumbai,
            to: $this->delhi,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['MH-DL-1'],
            ]],
            actor: $this->actor,
            idempotencyKey: 'ibt-reverse-1',
        );

        $invoice = $issued->statutoryInvoice;
        $this->assertSame($this->configuredSellerGstin('mumbai'), $invoice->seller_gstin);
        $this->assertSame('07AAICP1128M1Z9', $invoice->buyer_gstin);

        $this->interBranch->dispatch($issued, $this->actor);
        $this->interBranch->receive($issued->fresh(), $this->actor);

        $serial = InventorySerial::query()->where('serial_number', 'MH-DL-1')->firstOrFail();
        $this->assertSame($this->delhi->id, $serial->branch_id);
        $this->assertSame(InventorySerialStatus::Available, $serial->status);
    }

    public function test_non_serialized_quantity_journey(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'CABLE-IBT',
            'name' => 'Cable',
            'hsn_code' => '85444299',
            'gst_percentage' => 18,
            'unit_price' => 50,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $this->stock->stockInQuantity($product, $this->delhi, 5, $this->actor);

        $issued = $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 3,
            ]],
            actor: $this->actor,
            idempotencyKey: 'ibt-qty-1',
        );

        $this->interBranch->dispatch($issued, $this->actor);
        $this->interBranch->receive($issued->fresh(), $this->actor);

        $this->assertSame(2, (int) $product->balances()->where('branch_id', $this->delhi->id)->value('available_qty'));
        $this->assertSame(3, (int) $product->balances()->where('branch_id', $this->mumbai->id)->value('available_qty'));
    }

    public function test_sold_serial_is_rejected(): void
    {
        $product = $this->serializedProduct('SKU-SOLD');
        $this->stock->stockInSerialized($product, $this->delhi, ['SOLD-1'], $this->actor);

        app(PosSaleService::class)->completeSale(
            branch: $this->delhi,
            customer: ['name' => 'Buyer', 'phone' => '9000000102'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SOLD-1'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );

        $this->expectException(ValidationException::class);
        $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SOLD-1'],
            ]],
            actor: $this->actor,
            idempotencyKey: 'ibt-sold-1',
        );
    }

    public function test_reserved_serial_is_rejected(): void
    {
        $product = $this->serializedProduct('SKU-RSV');
        $this->stock->stockInSerialized($product, $this->delhi, ['RSV-1'], $this->actor);
        $this->stock->reserveSerials($this->delhi, ['RSV-1'], $this->actor);

        $this->expectException(ValidationException::class);
        $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['RSV-1'],
            ]],
            actor: $this->actor,
            idempotencyKey: 'ibt-rsv-1',
        );
    }

    public function test_serial_from_wrong_branch_is_rejected(): void
    {
        $product = $this->serializedProduct('SKU-WRONG-BR');
        $this->stock->stockInSerialized($product, $this->mumbai, ['WRONG-BR-1'], $this->actor);

        $this->expectException(ValidationException::class);
        $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['WRONG-BR-1'],
            ]],
            actor: $this->actor,
            idempotencyKey: 'ibt-wrong-br',
        );
    }

    public function test_issue_is_idempotent(): void
    {
        $product = $this->serializedProduct('SKU-IDEM');
        $this->stock->stockInSerialized($product, $this->delhi, ['IDEM-1'], $this->actor);

        $first = $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['IDEM-1'],
            ]],
            actor: $this->actor,
            idempotencyKey: 'idem-key-1',
        );
        $second = $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['IDEM-1'],
            ]],
            actor: $this->actor,
            idempotencyKey: 'idem-key-1',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, InterBranchTransaction::query()->count());
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_duplicate_dispatch_and_receive_are_idempotent(): void
    {
        $product = $this->serializedProduct('SKU-DUP-OPS');
        $this->stock->stockInSerialized($product, $this->delhi, ['DUP-OPS-1'], $this->actor);

        $issued = $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['DUP-OPS-1'],
            ]],
            actor: $this->actor,
            idempotencyKey: Str::uuid()->toString(),
        );

        $this->interBranch->dispatch($issued, $this->actor);
        $this->interBranch->dispatch($issued->fresh(), $this->actor);

        $this->interBranch->receive($issued->fresh(), $this->actor);
        $this->interBranch->receive($issued->fresh(), $this->actor);

        $this->assertSame(InterBranchTransactionStatus::Completed, $issued->fresh()->status);
        $this->assertSame(1, InventorySerial::query()->where('serial_number', 'DUP-OPS-1')->where('branch_id', $this->mumbai->id)->count());
    }

    public function test_cancel_before_dispatch_releases_reservation(): void
    {
        $product = $this->serializedProduct('SKU-CANCEL');
        $this->stock->stockInSerialized($product, $this->delhi, ['CANCEL-1'], $this->actor);

        $issued = $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['CANCEL-1'],
            ]],
            actor: $this->actor,
            idempotencyKey: 'ibt-cancel-1',
        );

        $this->interBranch->cancel($issued, $this->actor, 'Test cancel');

        $serial = InventorySerial::query()->where('serial_number', 'CANCEL-1')->firstOrFail();
        $this->assertSame(InventorySerialStatus::Available, $serial->status);
        $this->assertSame(InterBranchTransactionStatus::Cancelled, $issued->fresh()->status);
    }

    public function test_existing_manual_transfer_still_completes_immediately(): void
    {
        $product = $this->serializedProduct('SKU-MANUAL');
        $this->stock->stockInSerialized($product, $this->delhi, ['MANUAL-1'], $this->actor);

        $transfer = $this->stock->transferSerials($this->delhi, $this->mumbai, ['MANUAL-1'], $this->actor);

        $serial = InventorySerial::query()->where('serial_number', 'MANUAL-1')->firstOrFail();
        $this->assertSame($this->mumbai->id, $serial->branch_id);
        $this->assertSame(InventorySerialStatus::Available, $serial->status);
        $this->assertTrue(
            \App\Models\InventoryMovement::query()
                ->where('transfer_id', $transfer->id)
                ->where('type', InventoryMovementType::TransferIn)
                ->exists()
        );
    }

    public function test_inter_branch_does_not_create_inventory_sale_or_mark_serial_sold_on_issue(): void
    {
        $product = $this->serializedProduct('SKU-NO-SALE');
        $this->stock->stockInSerialized($product, $this->delhi, ['NO-SALE-1'], $this->actor);

        $this->interBranch->issue(
            from: $this->delhi,
            to: $this->mumbai,
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['NO-SALE-1'],
            ]],
            actor: $this->actor,
            idempotencyKey: 'ibt-no-sale',
        );

        $this->assertSame(0, InventorySale::query()->count());
        $this->assertSame(
            InventorySerialStatus::Reserved,
            InventorySerial::query()->where('serial_number', 'NO-SALE-1')->value('status'),
        );
    }

    private function serializedProduct(string $sku = 'SKU-IBT'): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Inter-branch product',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
