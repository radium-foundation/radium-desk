<?php

namespace Tests\Feature\ServicePos;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceIssuancePolicyMode;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\OutboxEvent;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ServicePos\ServiceQuoteService;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class ServicePosEinvoiceParityTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER_GSTIN = '33AAECA0303M1Z6';

    private ServiceQuoteService $quotes;

    private StatutoryInvoiceService $invoices;

    private User $admin;

    private InventoryBranch $branch;

    private ServiceItem $freightItem;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-22 12:00:00');
        Storage::fake('local');
        Http::fake();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'fake',
            'statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::AllEligibleB2b->value,
        ]);

        $this->quotes = app(ServiceQuoteService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $category = ServiceCategory::query()->create([
            'code' => 'freight',
            'name' => 'Freight',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->freightItem = ServiceItem::query()->create([
            'category_id' => $category->id,
            'code' => 'RBSMARKETS',
            'name' => 'Secondary Freight Reverse Auction',
            'sac_code' => '998311',
            'gst_rate' => 18,
            'price_ex_gst' => 50000,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_structured_billing_and_payment_reference_propagate_to_invoice(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Sundrop Brands Limited',
            'phone' => '9704890618',
            'gstin' => self::BUYER_GSTIN,
        ]);

        $structured = [
            'line1' => 'SF NO. 384/9A CODEA PARK ROAD',
            'city' => 'Coimbatore',
            'state' => 'Tamil Nadu',
            'pincode' => '641107',
        ];

        $quote = $this->quotes->createQuote(
            customer: $customer,
            branch: $this->branch,
            lines: [['service_item_id' => $this->freightItem->id, 'qty' => 1]],
            actor: $this->admin,
            billingAddress: $structured['line1'],
            billingState: 'Tamil Nadu',
            placeOfSupplyState: 'Tamil Nadu',
            buyerGstin: self::BUYER_GSTIN,
            billingAddressStructured: $structured,
            paymentReference: 'PO-998311-TEST',
        );

        $this->assertSame($structured, $quote->billing_address_structured);
        $this->assertSame('PO-998311-TEST', $quote->payment_reference);

        $order = $this->quotes->convertToOrder($quote, $this->admin);
        $this->assertSame($structured, $order->billing_address_structured);
        $this->assertSame('PO-998311-TEST', $order->payment_reference);

        $invoice = $this->invoices->issueFromServiceOrder($order->fresh(['lines.serviceItem']), $this->admin);
        $this->assertSame($structured, $invoice->billing_address_structured);
        $this->assertSame('PO-998311-TEST', $invoice->payment_reference);
        $this->assertSame('998311', $invoice->items->first()?->hsn_sac);
        $this->assertSame('OTH', $invoice->items->first()?->uqc);
    }

    public function test_desk_service_b2b_payload_is_submittable(): void
    {
        $invoice = $this->issueFreightInvoice();
        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertTrue($payload->isSubmittable());
        $this->assertNotContains('missing_buyer_pin', $payload->gaps);
        $this->assertNotContains('missing_buyer_loc', $payload->gaps);
        $this->assertNotContains('missing_uqc', $payload->gaps);
        $this->assertNotContains('missing_is_servc', $payload->gaps);
    }

    public function test_desk_service_einvoice_lifecycle_queues_and_submits_without_duplicate(): void
    {
        $invoice = $this->issueFreightInvoice();
        $fake = $this->bindFakeGateway();

        $this->invoices->queueEinvoiceIfEligible($invoice);
        $event = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();

        app(EInvoiceProcessor::class)->process($event);

        $this->assertSame(1, $fake->submitCount);
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertTrue($record?->hasIssuedIrn());

        $this->invoices->queueEinvoiceIfEligible($invoice->fresh(['items']));
        app(EInvoiceProcessor::class)->process($event->fresh());
        $this->assertSame(1, $fake->submitCount);
        Http::assertNothingSent();
    }

    public function test_reevaluate_promotes_skipped_record_without_touching_issued_invoice(): void
    {
        $invoice = $this->issueFreightInvoice();
        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'status' => EInvoiceRecordStatus::Skipped->value,
            'response_payload' => ['skip_reason' => 'irp_fields_incomplete'],
        ]);

        $this->invoices->reevaluateEinvoiceEligibility($invoice->fresh(['items']));

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(EInvoiceRecordStatus::Queued->value, $record?->status);
        $this->assertNotNull(
            OutboxEvent::query()
                ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
                ->first()
        );
    }

    public function test_service_sales_route_lists_orders(): void
    {
        $invoice = $this->issueFreightInvoice();
        $order = ServiceOrder::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('service-pos.sales.index'))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee($order->quote?->quote_number ?? '')
            ->assertSee($invoice->invoice_number);
    }

    public function test_counter_accepts_structured_billing_via_http(): void
    {
        $this->actingAs($this->admin)->post(route('service-pos.quotes.store'), [
            'branch_id' => $this->branch->id,
            'customer_name' => 'Sundrop Brands Limited',
            'customer_phone' => '9704890619',
            'customer_email' => 'buyer@example.test',
            'buyer_gstin' => self::BUYER_GSTIN,
            'billing_address' => 'SF NO. 384/9A CODEA PARK ROAD',
            'billing_city' => 'Coimbatore',
            'billing_state' => 'Tamil Nadu',
            'billing_pincode' => '641107',
            'place_of_supply_state' => 'Tamil Nadu',
            'payment_reference' => 'CUST-PO-42',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [
                ['service_item_id' => $this->freightItem->id, 'qty' => 1],
            ],
        ])->assertRedirect();

        $quote = ServiceQuote::query()->latest('id')->firstOrFail();
        $this->assertSame('Coimbatore', $quote->billing_address_structured['city'] ?? null);
        $this->assertSame('641107', $quote->billing_address_structured['pincode'] ?? null);
        $this->assertSame('CUST-PO-42', $quote->payment_reference);
    }

    public function test_b2b_quote_requires_city_and_pin(): void
    {
        $this->actingAs($this->admin)->post(route('service-pos.quotes.store'), [
            'branch_id' => $this->branch->id,
            'customer_name' => 'B2B Missing PIN',
            'customer_phone' => '9704890620',
            'buyer_gstin' => self::BUYER_GSTIN,
            'billing_address' => 'Some address',
            'billing_state' => 'Tamil Nadu',
            'place_of_supply_state' => 'Tamil Nadu',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [
                ['service_item_id' => $this->freightItem->id, 'qty' => 1],
            ],
        ])->assertSessionHasErrors(['billing_city', 'billing_pincode']);
    }

    private function issueFreightInvoice(): StatutoryInvoice
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Sundrop Brands Limited',
            'phone' => '9704890618',
            'gstin' => self::BUYER_GSTIN,
        ]);

        $quote = $this->quotes->createQuote(
            customer: $customer,
            branch: $this->branch,
            lines: [['service_item_id' => $this->freightItem->id, 'qty' => 1]],
            actor: $this->admin,
            billingAddress: 'SF NO. 384/9A CODEA PARK ROAD',
            billingState: 'Tamil Nadu',
            placeOfSupplyState: 'Tamil Nadu',
            buyerGstin: self::BUYER_GSTIN,
            billingAddressStructured: [
                'line1' => 'SF NO. 384/9A CODEA PARK ROAD',
                'city' => 'Coimbatore',
                'state' => 'Tamil Nadu',
                'pincode' => '641107',
            ],
            paymentReference: 'PO-FREIGHT-1',
        );

        $order = $this->quotes->convertToOrder($quote, $this->admin);

        return $this->invoices
            ->issueFromServiceOrder($order->fresh(['lines.serviceItem']), $this->admin)
            ->fresh(['items']);
    }

    private function bindFakeGateway(): FakeEInvoiceGateway
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            ackNo: 'ACK-SVC-1',
            ackDate: '2026-09-22 12:01:00',
            signedQr: 'signed-qr-token',
        ));
        $this->app->instance(EInvoiceGateway::class, $fake);
        $this->app->forgetInstance(EInvoiceProcessor::class);

        return $fake;
    }
}
