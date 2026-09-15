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
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
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
            'uqc' => 'NOS',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($this->product, $this->branch, 20, $this->seller);

        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);
    }

    public function test_counter_captures_gstin_billing_address_and_place_of_supply_on_the_sale(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '07aaaaa0000a1z5',
                'billing_address' => '12 Connaught Place, New Delhi',
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'billing_pincode' => '110001',
                'place_of_supply_state' => 'Delhi',
                'idempotency_key' => 'snap-full-1',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'snap-full-1')->firstOrFail();
        $this->assertSame(InventorySaleStatus::Completed, $sale->status);
        $this->assertSame('07AAAAA0000A1Z5', $sale->buyer_gstin);
        $this->assertSame('12 Connaught Place, New Delhi', $sale->billing_address);
        $this->assertSame([
            'line1' => '12 Connaught Place, New Delhi',
            'city' => 'New Delhi',
            'state' => 'Delhi',
            'pincode' => '110001',
        ], $sale->billing_address_structured);
        $this->assertSame('Delhi', $sale->place_of_supply_state);
        $this->assertMatchesRegularExpression('/^INV-DELHI-RETAIL-\d{4}-\d{5}$/', (string) $sale->invoice_number);
        $this->assertNotNull($sale->statutory_invoice_id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertNotSame($sale->invoice_number, StatutoryInvoice::query()->value('invoice_number'));
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

    public function test_b2c_sale_without_explicit_place_of_supply_defaults_to_branch_state_and_mints(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'idempotency_key' => 'snap-no-pos-1',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'snap-no-pos-1')->firstOrFail();
        $this->assertSame('Delhi', $sale->place_of_supply_state);
        $this->assertNotNull($sale->statutory_invoice_id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertMatchesRegularExpression('/^INV-DELHI-RETAIL-\d{4}-\d{5}$/', (string) $sale->invoice_number);

        $eligibility = app(StatutoryMintEligibility::class)->evaluateSale($sale);
        $this->assertTrue($eligibility->eligible);
    }

    public function test_customer_gstin_from_the_form_is_snapshotted_and_later_master_edits_do_not_change_it(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'Repeat Buyer',
            'phone' => '9000000091',
            'gstin' => '29AAAAA0000A1Z5',
        ]);

        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '29AAAAA0000A1Z5',
                'place_of_supply_state' => 'Karnataka',
                'billing_city' => 'Bengaluru',
                'billing_state' => 'Karnataka',
                'billing_pincode' => '560001',
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

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertNotNull($sale->fresh()->statutory_invoice_id);
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
        $unmappedBranch = InventoryBranch::query()->create([
            'code' => 'CHENNAI-RETAIL',
            'name' => 'Chennai Retail',
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInQuantity($this->product, $unmappedBranch, 5, $this->seller);
        $blocked = app(PosSaleService::class)->completeSale(
            branch: $unmappedBranch,
            customer: ['name' => 'Blocked', 'phone' => '9000000093'],
            lines: [['product_id' => $this->product->id, 'qty' => 1]],
            paymentMethod: 'Cash',
            actor: $this->seller,
        );

        $this->actingAs($admin)
            ->get(route('finance.invoices.pending'))
            ->assertOk()
            ->assertDontSee($ready->sale_no)
            ->assertSee($blocked->sale_no)
            ->assertSee('Place of supply missing');

        $this->actingAs($admin)
            ->post(route('finance.invoices.sales.issue', $blocked))
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertNotNull($ready->fresh()->statutory_invoice_id);
        $this->assertNull($blocked->fresh()->statutory_invoice_id);

        $this->actingAs($admin)
            ->post(route('finance.invoices.sales.issue', $ready))
            ->assertRedirect();

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame('INV-07671', StatutoryInvoice::query()->value('invoice_number'));
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
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'billing_pincode' => '110001',
                'place_of_supply_state' => 'Delhi',
            ],
        );

        $this->assertSame('07AAAAA0000A1Z5', $intent->cart_payload['statutory']['buyer_gstin'] ?? null);
        $this->assertSame('New Delhi', $intent->cart_payload['statutory']['billing_city'] ?? null);
        $this->assertSame('110001', $intent->cart_payload['statutory']['billing_pincode'] ?? null);
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
        $this->assertSame('New Delhi', $sale->billing_address_structured['city'] ?? null);
        $this->assertSame('110001', $sale->billing_address_structured['pincode'] ?? null);
        $this->assertNotNull($sale->statutory_invoice_id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(1, PosPaymentIntent::query()->count());
    }

    public function test_b2b_sale_requires_city_state_and_pin(): void
    {
        $this->actingAs($this->seller)
            ->from(route('pos.counter.create', ['branch_id' => $this->branch->id]))
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '07AAAAA0000A1Z5',
                'billing_address' => '12 Connaught Place',
                'place_of_supply_state' => 'Delhi',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors(['billing_city', 'billing_state', 'billing_pincode']);

        $this->assertSame(0, InventorySale::query()->count());
    }

    public function test_b2b_sale_rejects_missing_city(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '07AAAAA0000A1Z5',
                'billing_state' => 'Delhi',
                'billing_pincode' => '110001',
                'place_of_supply_state' => 'Delhi',
            ]))
            ->assertSessionHasErrors('billing_city');

        $this->assertSame(0, InventorySale::query()->count());
    }

    public function test_b2b_sale_rejects_missing_pin(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '07AAAAA0000A1Z5',
                'billing_city' => 'New Delhi',
                'billing_state' => 'Delhi',
                'place_of_supply_state' => 'Delhi',
            ]))
            ->assertSessionHasErrors('billing_pincode');

        $this->assertSame(0, InventorySale::query()->count());
    }

    public function test_b2b_sale_rejects_missing_state(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'buyer_gstin' => '07AAAAA0000A1Z5',
                'billing_city' => 'New Delhi',
                'billing_pincode' => '110001',
                'place_of_supply_state' => 'Delhi',
            ]))
            ->assertSessionHasErrors('billing_state');

        $this->assertSame(0, InventorySale::query()->count());
    }

    public function test_b2c_sale_does_not_require_irn_address_fields(): void
    {
        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'idempotency_key' => 'snap-b2c-no-irn-address',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'snap-b2c-no-irn-address')->firstOrFail();
        $this->assertNull($sale->buyer_gstin);
        $this->assertNull($sale->billing_address_structured);
        $this->assertSame('Delhi', $sale->place_of_supply_state);
        $this->assertNotNull($sale->statutory_invoice_id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_blank_form_gstin_does_not_copy_master_gstin_onto_the_sale(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'ABC MOBILE MART',
            'phone' => '8279573885',
            'gstin' => '09ANQPA2385P1ZB',
        ]);

        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), $this->payload([
                'customer_name' => 'ABC MOBILE MART',
                'customer_phone' => '8279573885',
                'buyer_gstin' => '',
                'idempotency_key' => 'snap-b2c-keep-master-gstin',
            ]))
            ->assertRedirect();

        $sale = InventorySale::query()->where('idempotency_key', 'snap-b2c-keep-master-gstin')->firstOrFail();
        $this->assertNull($sale->buyer_gstin);
        $this->assertSame('09ANQPA2385P1ZB', $sale->customer?->gstin);
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
