<?php

namespace Tests\Feature\ServicePos;

use App\Enums\ServiceOrderPaymentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\ServiceItem;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServicePosUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-17 14:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->agent = User::factory()->create(['is_active' => true]);
        $this->agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_service_master_pages_and_authorization(): void
    {
        $this->actingAs($this->admin)->get(route('services.items.index'))->assertOk()->assertSee('Service master');
        $this->actingAs($this->admin)->get(route('services.items.create'))->assertOk();
        $this->actingAs($this->admin)->get(route('services.categories.index'))->assertOk();

        $this->actingAs($this->agent)->get(route('services.items.index'))->assertForbidden();
        $this->actingAs($this->agent)->get(route('service-pos.counter.create'))->assertForbidden();
    }

    public function test_service_item_crud_and_toggle(): void
    {
        $this->actingAs($this->admin)->post(route('services.items.store'), [
            'category_id' => ServiceItem::query()->first()->category_id,
            'code' => 'UI-TEST-01',
            'name' => 'UI Test Service',
            'sac_code' => '998313',
            'gst_rate' => 18,
            'price_ex_gst' => 100,
            'is_active' => 1,
        ])->assertRedirect();

        $item = ServiceItem::query()->where('code', 'UI-TEST-01')->firstOrFail();

        $this->actingAs($this->admin)->put(route('services.items.update', $item), [
            'category_id' => $item->category_id,
            'code' => 'UI-TEST-01',
            'name' => 'UI Test Service Updated',
            'sac_code' => '998313',
            'gst_rate' => 18,
            'price_ex_gst' => 150,
            'is_active' => 1,
        ])->assertRedirect();

        $this->actingAs($this->admin)->patch(route('services.items.toggle', $item))->assertRedirect();
        $this->assertFalse($item->fresh()->is_active);
    }

    public function test_inactive_service_rejected_on_quote_create(): void
    {
        $item = ServiceItem::query()->where('code', 'DEV-RD-1Y')->firstOrFail();
        $item->update(['is_active' => false]);

        $this->actingAs($this->admin)->post(route('service-pos.quotes.store'), $this->quotePayload([
            ['service_item_id' => $item->id, 'qty' => 1],
        ]))->assertSessionHasErrors();
    }

    public function test_full_acceptance_scenario_via_http(): void
    {
        $beforeSales = InventorySale::query()->count();

        $rd = ServiceItem::query()->where('code', 'DEV-RD-1Y')->firstOrFail();
        $amc = ServiceItem::query()->where('code', 'DEV-AMC-1Y')->firstOrFail();

        $this->actingAs($this->admin)->get(route('service-pos.counter.create'))->assertOk()->assertSee('Service counter');

        $this->actingAs($this->admin)->post(route('service-pos.quotes.store'), $this->quotePayload([
            ['service_item_id' => $rd->id, 'qty' => 1],
            ['service_item_id' => $amc->id, 'qty' => 1],
            ['description' => 'Custom consulting', 'qty' => 1, 'unit_price_ex_gst' => 1000, 'sac_code' => '998596', 'gst_rate' => 18],
        ]))->assertRedirect();

        $quote = ServiceQuote::query()->latest('id')->firstOrFail();
        $this->actingAs($this->admin)->get(route('service-pos.quotes.show', $quote))->assertOk()->assertSee('not');
        $this->actingAs($this->admin)->get(route('service-pos.quotes.print', $quote))->assertOk()->assertSee('Internal Proforma');

        $this->actingAs($this->admin)->post(route('service-pos.quotes.convert', $quote))->assertRedirect();
        $order = ServiceOrder::query()->where('quote_id', $quote->id)->firstOrFail();
        $this->actingAs($this->admin)->post(route('service-pos.quotes.convert', $quote))->assertRedirect(route('service-pos.orders.show', $order));

        $this->actingAs($this->admin)->post(route('finance.invoices.service-orders.issue', $order))->assertRedirect();
        $order->refresh();
        $invoice = $order->statutoryInvoice;
        $this->assertNotNull($invoice);
        $this->assertSame(StatutoryInvoiceChannel::DeskService->value, $invoice->channel->value);
        $this->assertSame(StatutoryInvoiceSourceType::ServiceOrder->value, $invoice->source_type);

        $this->actingAs($this->admin)->post(route('finance.invoices.service-orders.issue', $order))->assertRedirect();
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', $order->order_number)->count());

        $customer = InventoryCustomer::query()->where('phone', '9999900001')->firstOrFail();
        $partial = round((float) $invoice->invoice_value * 0.4, 2);

        $this->actingAs($this->admin)->post(route('finance.payments.store'), [
            'customer_id' => $customer->id,
            'statutory_invoice_id' => $invoice->id,
            'amount' => $partial,
            'method' => 'Bank Transfer',
            'payment_date' => '2026-09-17',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame(ServiceOrderPaymentStatus::Partial, $order->payment_status);

        $remaining = round((float) $invoice->fresh()->invoice_value - $partial, 2);
        $this->actingAs($this->admin)->post(route('finance.payments.store'), [
            'customer_id' => $customer->id,
            'statutory_invoice_id' => $invoice->id,
            'amount' => $remaining,
            'method' => 'Cash',
            'payment_date' => '2026-09-17',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $this->assertSame(ServiceOrderPaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(InventorySale::query()->count(), $beforeSales);

        $this->actingAs($this->admin)->get(route('finance.receivables.index'))->assertOk()->assertSee($invoice->invoice_number);
    }

    public function test_hardware_pos_still_deducts_stock(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'HW-REG',
            'name' => 'Hardware',
            'gst_percentage' => 18,
            'unit_price' => 1000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, ['SER-1'], $this->admin);

        app(PosSaleService::class)->completeSale(
            branch: $this->branch,
            customer: ['name' => 'HW', 'phone' => '8888800001'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['SER-1']]],
            paymentMethod: 'Cash',
            actor: $this->admin,
        );

        $this->assertSame(1, InventorySale::query()->count());
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function quotePayload(array $lines): array
    {
        return [
            'branch_id' => $this->branch->id,
            'customer_name' => 'Acceptance Customer',
            'customer_phone' => '9999900001',
            'billing_state' => 'Karnataka',
            'place_of_supply_state' => 'Karnataka',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $lines,
        ];
    }
}
