<?php

namespace Tests\Feature\Inventory;

use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
use App\Models\FinanceParty;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Pos\PosWalkInCompletionService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosWalkInCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    private InventoryProduct $product;

    private PosWalkInCompletionService $walkIn;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->seller->id,
            'branch_id' => $this->branch->id,
        ]);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'MFS110-WALKIN',
            'name' => 'Mantra walk-in',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($this->product, $this->branch, 10, $this->seller);
        $this->walkIn = app(PosWalkInCompletionService::class);

        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.walk_in_auto_issue_statutory' => true,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);
    }

    public function test_b2c_walk_in_completes_sale_and_mints_statutory_invoice_once(): void
    {
        $result = $this->walkIn->complete(
            branch: $this->branch,
            input: [
                'customer_type' => 'b2c',
                'customer_name' => 'Walk-in B2C',
                'customer_phone' => '9000001001',
                'place_of_supply_state' => 'Delhi',
            ],
            lines: [['product_id' => $this->product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $this->seller,
            idempotencyKey: 'walkin-b2c-1',
        );

        $this->assertTrue($result->invoiceIssued());
        $this->assertSame('INV-07671', $result->statutoryInvoice?->invoice_number);
        $this->assertSame('Walk-in B2C', $result->sale->snapshot_buyer_name);
        $this->assertSame('Delhi', $result->sale->place_of_supply_state);
        $this->assertNotNull($result->sale->finance_party_id);
        $this->assertGreaterThan(0, (float) $result->statutoryInvoice?->cgst);
        $this->assertGreaterThan(0, (float) $result->statutoryInvoice?->sgst);
        $this->assertSame(1, StatutoryInvoice::query()->count());

        $again = $this->walkIn->complete(
            branch: $this->branch,
            input: [
                'customer_type' => 'b2c',
                'customer_name' => 'Walk-in B2C',
                'customer_phone' => '9000001001',
                'place_of_supply_state' => 'Delhi',
            ],
            lines: [['product_id' => $this->product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $this->seller,
            idempotencyKey: 'walkin-b2c-1',
        );

        $this->assertSame($result->sale->id, $again->sale->id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_b2b_walk_in_requires_structured_billing_fields(): void
    {
        $this->expectException(ValidationException::class);

        $this->walkIn->complete(
            branch: $this->branch,
            input: [
                'customer_type' => 'b2b',
                'customer_name' => 'Acme Retail',
                'customer_phone' => '9000001002',
                'buyer_gstin' => '07AAAAA0000A1Z5',
                'place_of_supply_state' => 'Delhi',
            ],
            lines: [['product_id' => $this->product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $this->seller,
        );
    }

    public function test_b2b_walk_in_mints_invoice_and_queues_irn_when_eligible(): void
    {
        config(['statutory_invoices.einvoice.provider' => 'whitebooks']);

        $result = $this->walkIn->complete(
            branch: $this->branch,
            input: [
                'customer_type' => 'b2b',
                'customer_name' => 'Acme Retail Pvt Ltd',
                'customer_phone' => '9000001003',
                'customer_email' => 'billing@acme.test',
                'buyer_gstin' => '07AAAAA0000A1Z5',
                'billing_address' => '12 Connaught Place',
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'billing_postal_code' => '110001',
                'place_of_supply_state' => 'Delhi',
            ],
            lines: [['product_id' => $this->product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $this->seller,
            idempotencyKey: 'walkin-b2b-1',
        );

        $this->assertTrue($result->invoiceIssued());
        $this->assertSame('07AAAAA0000A1Z5', $result->statutoryInvoice?->buyer_gstin);
        $this->assertSame('b2b', $result->sale->customer_type);
        $this->assertNotNull(FinanceParty::query()->find($result->sale->finance_party_id));

        $record = EInvoiceRecord::query()->where('invoice_id', $result->statutoryInvoice?->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(EInvoiceRecordStatus::Queued->value, $record->status);
    }

    public function test_invalid_walk_in_place_of_supply_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->walkIn->complete(
            branch: $this->branch,
            input: [
                'customer_type' => 'b2c',
                'customer_name' => 'Wrong POS',
                'customer_phone' => '9000001004',
                'place_of_supply_state' => 'Maharashtra',
            ],
            lines: [['product_id' => $this->product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $this->seller,
        );
    }

    public function test_counter_http_flow_issues_invoice_when_auto_issue_enabled(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branch->id,
                'customer_type' => 'b2c',
                'customer_name' => 'HTTP Walk-in',
                'customer_phone' => '9000001005',
                'payment_method' => 'Cash',
                'place_of_supply_state' => 'Delhi',
                'idempotency_key' => 'walkin-http-1',
                'lines' => [[
                    'product_id' => $this->product->id,
                    'qty' => 1,
                ]],
            ])
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'walkin-http-1')->firstOrFail();
        $this->assertNotNull($sale->statutory_invoice_id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }
}
