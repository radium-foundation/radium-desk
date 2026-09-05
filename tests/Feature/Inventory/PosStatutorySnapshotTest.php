<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventorySaleStatus;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBankAccountUpiProfile;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\PosPaymentIntent;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\Pos\PosUpiIntentService;
use App\Services\Pos\PosUpiVerificationService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosStatutorySnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->seller->id,
            'branch_id' => $this->branch->id,
        ]);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'MFS110-SNAP',
            'name' => 'Mantra snapshot',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($this->product, $this->branch, 20, $this->seller);

        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => 'TEST',
            'statutory_invoices.number_format' => '{series}-{seq:5}',
            'statutory_invoices.gstin_scope' => '07AAICP1128M1Z9',
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
        ]);
    }

    public function test_counter_captures_gstin_billing_address_and_place_of_supply_on_the_sale(): void
    {
        $this->actingAs($this->seller)
            ->get(route('pos.counter.create', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->assertSee('Buyer GSTIN')
            ->assertSee('Place of supply')
            ->assertSee('Delhi');

        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '07aaaaa0000a1z5',
                'billing_address' => '12 Connaught Place, New Delhi',
                'place_of_supply_state' => 'Delhi',
                'idempotency_key' => 'snap-full-1',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'snap-full-1')->firstOrFail();
        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
        $this->assertSame('07AAAAA0000A1Z5', $sale->buyer_gstin);
        $this->assertSame('12 Connaught Place, New Delhi', $sale->billing_address);
        $this->assertSame('Delhi', $sale->place_of_supply_state);
        $this->assertMatchesRegularExpression('/^INV-HQ-\d{4}-\d{5}$/', (string) $sale->invoice_number);
        $this->assertNull($sale->statutory_invoice_id);
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame('07AAAAA0000A1Z5', InventoryCustomer::query()->where('phone', '9000000091')->value('gstin'));
    }

    public function test_invalid_gstin_is_rejected_and_submitted_values_are_kept(): void
    {
        $this->actingAs($this->seller)
            ->from(route('pos.counter.create', ['branch_id' => $this->branch->id]))
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => 'NOT-A-GSTIN',
                'billing_address' => 'Keep this address',
                'place_of_supply_state' => 'Delhi',
                'customer_name' => 'Kept Customer',
            ]))
            ->assertRedirect(route('pos.counter.create', ['branch_id' => $this->branch->id]))
            ->assertSessionHasErrors('buyer_gstin')
            ->assertSessionHasInput('buyer_gstin', 'NOT-A-GSTIN')
            ->assertSessionHasInput('billing_address', 'Keep this address')
            ->assertSessionHasInput('place_of_supply_state', 'Delhi')
            ->assertSessionHasInput('customer_name', 'Kept Customer');

        $this->assertSame(0, InventorySale::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_unknown_place_of_supply_is_rejected(): void
    {
        $this->actingAs($this->seller)
            ->from(route('pos.counter.create', ['branch_id' => $this->branch->id]))
            ->post(route('pos.counter.store'), $this->payload([
                'place_of_supply_state' => 'Narnia',
            ]))
            ->assertSessionHasErrors('place_of_supply_state');

        $this->assertSame(0, InventorySale::query()->count());
    }

    public function test_sale_completes_without_place_of_supply_and_does_not_mint(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'idempotency_key' => 'snap-no-pos-1',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'snap-no-pos-1')->firstOrFail();
        $this->assertNull($sale->place_of_supply_state);
        $this->assertNull($sale->statutory_invoice_id);
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertMatchesRegularExpression('/^INV-HQ-\d{4}-\d{5}$/', (string) $sale->invoice_number);

        $eligibility = app(StatutoryMintEligibility::class)->evaluateSale($sale);
        $this->assertFalse($eligibility->eligible);
        $this->assertTrue($eligibility->missingPlaceOfSupply());
        $this->assertSame('Place of supply missing', $eligibility->staffSummary());

        $this->expectException(ValidationException::class);
        app(StatutoryInvoiceService::class)->issueFromPosSale($sale, $this->seller);
    }

    public function test_customer_gstin_default_is_copied_onto_the_sale_snapshot(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'Repeat Buyer',
            'phone' => '9000000091',
            'gstin' => '29AAAAA0000A1Z5',
        ]);

        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'place_of_supply_state' => 'Karnataka',
                'idempotency_key' => 'snap-default-gstin',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'snap-default-gstin')->firstOrFail();
        $this->assertSame('29AAAAA0000A1Z5', $sale->buyer_gstin);

        $sale->customer?->update(['gstin' => '27AAAAA0000A1Z5']);
        $this->assertSame('29AAAAA0000A1Z5', $sale->fresh()->buyer_gstin);

        $invoice = app(StatutoryInvoiceService::class)->issueFromPosSale($sale->fresh(), $this->seller);
        $this->assertSame('29AAAAA0000A1Z5', $invoice->buyer_gstin);
        $this->assertSame('Karnataka', $invoice->place_of_supply_state);
        $this->assertSame($invoice->id, $sale->fresh()->statutory_invoice_id);
    }

    public function test_show_and_reprint_do_not_mint_a_statutory_invoice(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'place_of_supply_state' => 'Delhi',
                'idempotency_key' => 'snap-reprint-1',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'snap-reprint-1')->firstOrFail();

        $this->actingAs($this->seller)
            ->get(route('pos.sales.show', $sale))
            ->assertOk()
            ->assertSee('Sale statutory snapshot')
            ->assertSee('Place of supply Delhi');

        $this->actingAs($this->seller)
            ->get(route('pos.sales.invoice', $sale))
            ->assertOk()
            ->assertSee($sale->invoice_number)
            ->assertSee('not a GST tax invoice');

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertNull($sale->fresh()->statutory_invoice_id);
        $this->assertSame($sale->invoice_number, $sale->fresh()->invoice_number);
    }

    public function test_finance_pending_distinguishes_ready_and_missing_place_of_supply(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $ready = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Ready', 'phone' => '9000000092'],
            lines: [['product_id' => $this->product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $this->seller,
            statutory: ['place_of_supply_state' => 'Delhi'],
        );
        $blocked = app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Blocked', 'phone' => '9000000093'],
            lines: [['product_id' => $this->product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $this->seller,
        );

        $this->actingAs($admin)
            ->get(route('finance.invoices.pending'))
            ->assertOk()
            ->assertSee($ready->sale_no)
            ->assertSee($blocked->sale_no)
            ->assertSee('Ready to issue')
            ->assertSee('Place of supply missing');

        $this->actingAs($admin)
            ->post(route('finance.invoices.sales.issue', $blocked))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame(0, StatutoryInvoice::query()->count());

        $this->actingAs($admin)
            ->post(route('finance.invoices.sales.issue', $ready))
            ->assertRedirect();

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame('TEST-00001', StatutoryInvoice::query()->value('invoice_number'));
    }

    public function test_upi_intent_carries_the_sale_snapshot_through_to_complete(): void
    {
        $account = FinanceBankAccount::query()->create([
            'bank_name' => 'Snapshot Bank',
            'account_name' => 'Collection',
            'last_four' => '1111',
            'is_active' => true,
        ]);
        FinanceBankAccountUpiProfile::query()->create([
            'finance_bank_account_id' => $account->id,
            'vpa' => 'snap@upi',
            'payee_name' => 'Snap Payee',
            'is_enabled' => true,
        ]);

        $intent = app(PosUpiIntentService::class)->create(
            branch: $this->branch,
            customer: ['name' => 'UPI Buyer', 'phone' => '9000000094'],
            lines: [['product_id' => $this->product->id, 'qty' => 1]],
            receivingBankAccountId: $account->id,
            actor: $this->seller,
            statutory: [
                'buyer_gstin' => '07AAAAA0000A1Z5',
                'billing_address' => 'UPI billing lane',
                'place_of_supply_state' => 'Delhi',
            ],
        );

        $this->assertSame('07AAAAA0000A1Z5', $intent->cart_payload['statutory']['buyer_gstin'] ?? null);
        $this->assertSame(0, InventorySale::query()->count());

        $sale = app(PosUpiVerificationService::class)->confirm(
            $intent,
            $this->seller,
            'UTRSNAP001',
            true,
            $intent->amount,
        );

        $this->assertInstanceOf(InventorySale::class, $sale);
        $this->assertSame('07AAAAA0000A1Z5', $sale->buyer_gstin);
        $this->assertSame('UPI billing lane', $sale->billing_address);
        $this->assertSame('Delhi', $sale->place_of_supply_state);
        $this->assertNull($sale->statutory_invoice_id);
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(1, PosPaymentIntent::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'customer_name' => 'Walk-in',
            'customer_phone' => '9000000091',
            'payment_method' => 'Cash',
            'discount' => 0,
            'lines' => [[
                'product_id' => $this->product->id,
                'qty' => 1,
                'unit_price' => $this->product->unit_price,
                'discount' => 0,
            ]],
        ], $overrides);
    }
}
