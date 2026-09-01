<?php

namespace Tests\Feature\Inventory;

use App\Enums\FinanceJournalSourceType;
use App\Enums\InventoryFinanceHandoffStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Models\FinanceJournal;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryPosOperationalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $hardware;

    private InventoryBranch $branchA;

    private InventoryBranch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Ops Admin']);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->hardware = User::factory()->create(['is_active' => true, 'name' => 'Counter Hardware']);
        $this->hardware->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->branchA = InventoryBranch::query()->create([
            'code' => 'TSTA',
            'name' => 'Test Counter A',
            'is_active' => true,
        ]);
        $this->branchB = InventoryBranch::query()->create([
            'code' => 'TSTB',
            'name' => 'Test Warehouse B',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->hardware->id,
            'branch_id' => $this->branchA->id,
        ]);
    }

    public function test_operator_workflow_catalog_serial_lifecycle_sale_invoice_and_finance(): void
    {
        $this->actingAs($this->admin)
            ->post(route('inventory.products.store'), [
                'sku' => 'MFS110-QA',
                'name' => 'Mantra MFS110 QA',
                'gst_percentage' => 18,
                'unit_price' => 2500,
                'is_serialized' => '1',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('inventory.products.store'), [
                'sku' => 'OTG-QA',
                'name' => 'OTG Cable QA',
                'gst_percentage' => 18,
                'unit_price' => 50,
                'is_active' => '1',
                'variants' => [[
                    'sku' => 'OTG-QA-1M',
                    'name' => '1 metre',
                    'unit_price' => 40,
                    'is_active' => '1',
                ]],
            ])
            ->assertRedirect();

        $serialized = InventoryProduct::query()->where('sku', 'MFS110-QA')->firstOrFail();
        $quantity = InventoryProduct::query()->where('sku', 'OTG-QA')->firstOrFail();
        $variant = $quantity->variants()->where('sku', 'OTG-QA-1M')->firstOrFail();

        $this->actingAs($this->hardware)
            ->post(route('inventory.stock.store'), [
                'branch_id' => $this->branchA->id,
                'product_id' => $serialized->id,
                'serials' => "QA-SN-001\nQA-SN-002\nQA-SN-DUP",
            ])
            ->assertRedirect(route('inventory.stock.index'));

        $this->actingAs($this->hardware)
            ->post(route('inventory.stock.store'), [
                'branch_id' => $this->branchA->id,
                'product_id' => $serialized->id,
                'serials' => 'QA-SN-DUP',
            ])
            ->assertSessionHasErrors('serials');

        $this->actingAs($this->hardware)
            ->post(route('inventory.stock.store'), [
                'branch_id' => $this->branchA->id,
                'product_id' => $quantity->id,
                'variant_id' => $variant->id,
                'qty' => 5,
            ])
            ->assertRedirect(route('inventory.stock.index'));

        $stock = app(InventoryStockService::class);
        $reservation = $stock->reserveSerials($this->branchA, ['QA-SN-001'], $this->hardware);
        $this->assertSame(InventorySerialStatus::Reserved, InventorySerial::query()->where('serial_number', 'QA-SN-001')->value('status'));
        $stock->releaseReservation($reservation, $this->hardware);
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'QA-SN-001')->value('status'));

        $this->actingAs($this->admin)
            ->post(route('inventory.transfers.store'), [
                'from_branch_id' => $this->branchA->id,
                'to_branch_id' => $this->branchB->id,
                'mode' => 'serial',
                'serials' => 'QA-SN-002',
            ])
            ->assertRedirect();

        $moved = InventorySerial::query()->where('serial_number', 'QA-SN-002')->firstOrFail();
        $this->assertSame($this->branchB->id, $moved->branch_id);
        $this->assertSame(1, InventorySerial::query()->where('serial_number', 'QA-SN-002')->count());

        $this->actingAs($this->hardware)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branchA->id,
                'customer_name' => 'Walk-in',
                'customer_phone' => '9111100002',
                'payment_method' => 'Cash',
                'lines' => [[
                    'product_id' => $serialized->id,
                    'qty' => 1,
                    'serials' => 'QA-SN-002',
                ]],
            ])
            ->assertSessionHasErrors();

        $this->actingAs($this->hardware)
            ->get(route('pos.counter.create', ['branch_id' => $this->branchA->id]))
            ->assertOk()
            ->assertSee('Selling from')
            ->assertSee('Test Counter A')
            ->assertSee('Product / SKU search')
            ->assertSee('Complete sale')
            ->assertDontSee('Test Warehouse B');

        $this->actingAs($this->hardware)
            ->getJson(route('pos.products.search', ['branch_id' => $this->branchA->id, 'q' => 'MFS110']))
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'MFS110-QA')
            ->assertJsonPath('products.0.available_qty', 2);

        $this->actingAs($this->hardware)
            ->getJson(route('pos.serials.search', ['branch_id' => $this->branchA->id, 'q' => 'QA-SN']))
            ->assertOk()
            ->assertJsonPath('serials.0.serial_number', 'QA-SN-001');

        $response = $this->actingAs($this->hardware)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branchA->id,
                'customer_name' => 'QA Customer',
                'customer_phone' => '9111100001',
                'customer_email' => 'qa@example.test',
                'payment_method' => 'Cash',
                'discount' => 10,
                'idempotency_key' => 'qa-operator-sale-1',
                'lines' => [
                    [
                        'product_id' => $serialized->id,
                        'qty' => 1,
                        'serials' => 'QA-SN-001',
                    ],
                    [
                        'product_id' => $quantity->id,
                        'variant_id' => $variant->id,
                        'qty' => 2,
                    ],
                ],
            ]);

        $sale = InventorySale::query()->where('idempotency_key', 'qa-operator-sale-1')->firstOrFail();
        $response->assertRedirect(route('pos.sales.show', $sale));

        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
        $this->assertSame(InventoryFinanceHandoffStatus::Posted, $sale->finance_handoff_status);
        $this->assertNotNull($sale->finance_journal_id);
        $this->assertNotNull($sale->invoice_number);
        $this->assertSame(2580.00, (float) $sale->subtotal);
        $this->assertSame(10.00, (float) $sale->discount);
        $this->assertSame(464.40, (float) $sale->tax);
        $this->assertSame(3034.40, (float) $sale->total);
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'QA-SN-001')->value('status'));
        $this->assertSame(3, (int) $quantity->balances()->where('branch_id', $this->branchA->id)->where('variant_id', $variant->id)->value('available_qty'));

        $journal = FinanceJournal::query()->findOrFail($sale->finance_journal_id);
        $this->assertSame('pos_sale:'.$sale->id, $journal->idempotency_key);
        $this->assertSame('3034.40', $journal->totalDebits());
        $this->assertSame('3034.40', $journal->totalCredits());

        $this->actingAs($this->hardware)
            ->get(route('pos.sales.show', $sale))
            ->assertOk()
            ->assertSee('QA Customer')
            ->assertSee($sale->invoice_number)
            ->assertSee('OTG-QA-1M')
            ->assertDontSee('Cancel sale');

        $this->actingAs($this->hardware)
            ->get(route('pos.sales.invoice', $sale))
            ->assertOk()
            ->assertSee($sale->invoice_number)
            ->assertSee('Internal Desk invoice')
            ->assertSee('QA-SN-001')
            ->assertSee('OTG-QA-1M');

        $this->actingAs($this->hardware)
            ->get(route('pos.sales.index'))
            ->assertOk()
            ->assertSee($sale->sale_no);

        $this->actingAs($this->hardware)
            ->get(route('inventory.movements.index'))
            ->assertOk()
            ->assertSee('QA-SN-001');

        $this->actingAs($this->hardware)
            ->post(route('pos.sales.cancel', $sale), ['reason' => 'QA cancel'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->from(route('pos.sales.show', $sale))
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branchA->id,
                'customer_name' => 'QA Customer',
                'customer_phone' => '9111100001',
                'payment_method' => 'Cash',
                'idempotency_key' => 'qa-operator-sale-1',
                'lines' => [[
                    'product_id' => $serialized->id,
                    'qty' => 1,
                    'serials' => 'QA-SN-001',
                ]],
            ])
            ->assertRedirect(route('pos.sales.show', $sale));

        $this->assertSame(1, InventorySale::query()->count());
        $this->assertSame(InventorySerialStatus::Sold, InventorySerial::query()->where('serial_number', 'QA-SN-001')->value('status'));
    }

    public function test_cancel_restores_stock_and_keeps_the_existing_finance_journal(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-CAN',
            'name' => 'Cancel scanner',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branchA, ['QA-CAN-1'], $this->admin);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Cancel Customer', 'phone' => '9111100099'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['QA-CAN-1'],
            ]],
            paymentMethod: 'UPI',
            actor: $this->admin,
        );
        $journalId = $sale->finance_journal_id;
        $invoice = $sale->invoice_number;
        $this->assertNotNull($journalId);

        $this->actingAs($this->admin)
            ->post(route('pos.sales.cancel', $sale), ['reason' => 'Controlled QA cancel'])
            ->assertRedirect(route('pos.sales.show', $sale));

        $sale->refresh();
        $this->assertSame(InventorySaleStatus::Cancelled, $sale->status);
        $this->assertSame($invoice, $sale->invoice_number);
        $this->assertSame($journalId, $sale->finance_journal_id);
        $this->assertSame(InventoryFinanceHandoffStatus::Reversed, $sale->finance_handoff_status);
        $this->assertSame(InventorySerialStatus::Available, InventorySerial::query()->where('serial_number', 'QA-CAN-1')->value('status'));
        $this->assertTrue(InventoryMovement::query()->where('sale_id', $sale->id)->where('type', InventoryMovementType::SaleCancel)->exists());
        $this->assertSame(1, FinanceJournal::query()->where('id', $journalId)->count());
        $this->assertTrue(
            FinanceJournal::query()
                ->where('source_type', FinanceJournalSourceType::PosSale)
                ->where('idempotency_key', 'pos_sale:reverse:'.$sale->id.':'.$journalId)
                ->exists()
        );
        $this->assertSame(2, FinanceJournal::query()->count());

        $this->actingAs($this->admin)
            ->get(route('pos.sales.invoice', $sale))
            ->assertOk()
            ->assertSee($invoice)
            ->assertSee('Cancelled');
    }

    public function test_return_restores_quantity_and_posts_a_reverse_journal(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'OTG-RET',
            'name' => 'Return cable',
            'gst_percentage' => 18,
            'unit_price' => 80,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($product, $this->branchA, 4, $this->admin);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Return Customer', 'phone' => '9111100088'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 2,
            ]],
            paymentMethod: 'Card',
            actor: $this->admin,
        );

        $this->actingAs($this->admin)
            ->post(route('pos.sales.return', $sale), ['reason' => 'Controlled QA return'])
            ->assertRedirect(route('pos.sales.show', $sale));

        $this->assertSame(InventorySaleStatus::Returned, $sale->fresh()->status);
        $this->assertSame(4, (int) $product->balances()->where('branch_id', $this->branchA->id)->value('available_qty'));
        $this->assertSame(InventoryFinanceHandoffStatus::Reversed, $sale->fresh()->finance_handoff_status);
        $this->assertSame(2, FinanceJournal::query()->count());
        $this->assertTrue(
            FinanceJournal::query()
                ->where('source_type', FinanceJournalSourceType::PosSale)
                ->where('idempotency_key', 'like', 'pos_sale:reverse:'.$sale->id.':%')
                ->exists()
        );
        $this->assertTrue(InventoryMovement::query()->where('sale_id', $sale->id)->where('type', InventoryMovementType::Return)->exists());
    }

    public function test_sold_serial_cannot_be_sold_from_the_destination_after_it_has_already_sold(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-TWICE',
            'name' => 'Twice scanner',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branchA, ['QA-TWICE-1'], $this->admin);
        app(PosSaleService::class)->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'First', 'phone' => '9111100077'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['QA-TWICE-1'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->admin,
        );

        $this->actingAs($this->admin)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branchA->id,
                'customer_name' => 'Second',
                'customer_phone' => '9111100078',
                'payment_method' => 'Cash',
                'lines' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'serials' => 'QA-TWICE-1',
                ]],
            ])
            ->assertSessionHasErrors();
    }

    public function test_stock_in_form_exposes_variant_select_and_requires_it_when_product_has_variants(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'OTG-VAR',
            'name' => 'OTG with variant',
            'gst_percentage' => 18,
            'unit_price' => 50,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $variant = $product->variants()->create([
            'sku' => 'OTG-VAR-1M',
            'name' => '1 metre',
            'unit_price' => 40,
            'is_active' => true,
        ]);

        $this->actingAs($this->hardware)
            ->get(route('inventory.stock.create'))
            ->assertOk()
            ->assertSee('name="variant_id"', false)
            ->assertSee('POS sells those variants from this stock.');

        $this->actingAs($this->hardware)
            ->from(route('inventory.stock.create'))
            ->post(route('inventory.stock.store'), [
                'branch_id' => $this->branchA->id,
                'product_id' => $product->id,
                'qty' => 5,
            ])
            ->assertRedirect(route('inventory.stock.create'))
            ->assertSessionHasErrors('variant_id');

        $this->actingAs($this->hardware)
            ->post(route('inventory.stock.store'), [
                'branch_id' => $this->branchA->id,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'qty' => 5,
            ])
            ->assertRedirect(route('inventory.stock.index'));

        $this->assertSame(5, (int) $product->balances()->where('branch_id', $this->branchA->id)->where('variant_id', $variant->id)->value('available_qty'));
        $this->assertNull($product->balances()->where('branch_id', $this->branchA->id)->whereNull('variant_id')->value('available_qty'));
    }
}
