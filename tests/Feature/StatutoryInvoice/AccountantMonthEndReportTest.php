<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestService;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountantMonthEndReportTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private User $accountant;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole(RolePermissionSeeder::ROLE_ACCOUNTANT);
        $this->enableTestSeries();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_date_filter_includes_only_invoices_issued_in_the_period(): void
    {
        Carbon::setTestNow('2026-08-31 18:00:00');
        $this->invoices->mint($this->request('aug-1', paymentMethod: 'UPI', paymentReference: 'pay-aug'), $this->actor);

        Carbon::setTestNow('2026-09-01 09:00:00');
        $this->invoices->mint($this->request('sep-1', paymentMethod: 'Cash', paymentReference: 'pay-sep'), $this->actor);

        Carbon::setTestNow('2026-09-30 22:00:00');
        $this->invoices->mint($this->request('sep-2'), $this->actor);

        Carbon::setTestNow('2026-10-01 00:30:00');
        $this->invoices->mint($this->request('oct-1'), $this->actor);

        $csv = $this->actingAs($this->accountant)
            ->get(route('finance.reports.export', [
                'report' => 'register',
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-30',
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('TEST-00002', $csv);
        $this->assertStringContainsString('TEST-00003', $csv);
        $this->assertStringNotContainsString('TEST-00001', $csv);
        $this->assertStringNotContainsString('TEST-00004', $csv);

        $this->actingAs($this->accountant)
            ->get(route('finance.invoices.index', [
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-30',
            ]))
            ->assertOk()
            ->assertSee('TEST-00002')
            ->assertSee('TEST-00003')
            ->assertDontSee('TEST-00001')
            ->assertDontSee('TEST-00004');
    }

    public function test_gst_totals_skip_cancelled_and_do_not_fabricate_cgst_split(): void
    {
        Carbon::setTestNow('2026-09-10 12:00:00');
        $kept = $this->invoices->mint($this->request('gst-keep'), $this->actor);
        $cancelled = $this->invoices->mint($this->request('gst-cancel'), $this->actor);
        $this->invoices->cancel($cancelled, $this->actor, 'Wrong party');

        $this->actingAs($this->accountant)
            ->get(route('finance.reports.index', [
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-30',
            ]))
            ->assertOk()
            ->assertSee($cancelled->invoice_number)
            ->assertSee('Wrong party')
            ->assertSee('Unclassified tax');

        $gst = $this->actingAs($this->accountant)
            ->get(route('finance.reports.export', [
                'report' => 'gst',
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-30',
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('18.00,100.00,,,,18.00,18.00', $gst);

        $this->assertNull($kept->fresh()->cgst);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame('TEST-00002', $cancelled->fresh()->invoice_number);
    }

    public function test_invoice_lines_and_sales_exports_include_source_and_date(): void
    {
        Carbon::setTestNow('2026-09-12 11:15:00');
        $this->invoices->mint(
            $this->request(
                'line-1',
                channel: StatutoryInvoiceChannel::RdServiceIn,
                sourceOrderId: 'RD-1001',
                paymentMethod: 'UPI',
                paymentReference: 'cf_123',
            ),
            $this->actor,
        );

        $lines = $this->actingAs($this->accountant)
            ->get(route('finance.reports.export', ['report' => 'lines', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('invoice_number', $lines);
        $this->assertStringContainsString('TEST-00001', $lines);
        $this->assertStringContainsString('84716050', $lines);
        $this->assertStringContainsString('rdservice_in', $lines);
        $this->assertStringContainsString('RD-1001', $lines);

        $sales = $this->actingAs($this->accountant)
            ->get(route('finance.reports.export', ['report' => 'sales', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('2026-09-12,rdservice_in,issued,1,100.00,18.00,118.00', $sales);
    }

    public function test_collections_export_uses_stored_payment_fields_only(): void
    {
        Carbon::setTestNow('2026-09-05 10:00:00');
        $this->invoices->mint(
            $this->request('paid-1', paymentMethod: 'UPI', paymentReference: 'upi-99'),
            $this->actor,
        );
        $this->invoices->mint($this->request('unpaid-1'), $this->actor);

        $csv = $this->actingAs($this->accountant)
            ->get(route('finance.reports.export', ['report' => 'collections', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('statutory_invoice,TEST-00001', $csv);
        $this->assertStringContainsString('upi-99', $csv);
        $this->assertStringNotContainsString('TEST-00002', $csv);
    }

    public function test_channel_orders_report_shows_eligibility_without_inventing_tax_fields(): void
    {
        Carbon::setTestNow('2026-09-08 14:00:00');
        $this->ingestChannelOrder($this->eligibleChannelPayload('RD-ELIG'));
        $incomplete = $this->eligibleChannelPayload('RD-MISS');
        unset($incomplete['seller_gstin'], $incomplete['place_of_supply_state']);
        $incomplete['lines'][0]['hsn_sac'] = null;
        $this->ingestChannelOrder($incomplete);

        $this->actingAs($this->accountant)
            ->get(route('finance.reports.index', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
            ->assertOk()
            ->assertSee('CO-000001')
            ->assertSee('CO-000002')
            ->assertSee('seller GSTIN')
            ->assertSee('Eligible, not invoiced');

        $csv = $this->actingAs($this->accountant)
            ->get(route('finance.reports.export', ['report' => 'channel_orders', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('RD-ELIG', $csv);
        $this->assertStringContainsString('yes', $csv);
        $this->assertStringContainsString('RD-MISS', $csv);
        $this->assertStringContainsString('no', $csv);
        $this->assertStringContainsString('seller GSTIN', $csv);
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $missing = CommerceOrder::query()->with('items')->where('source_id', 'RD-MISS')->first();
        $this->assertNotNull($missing);
        $this->assertFalse((bool) $missing->invoice_eligible);
        $this->assertNull($missing->seller_gstin);
        $this->assertNull($missing->place_of_supply_state);
        $this->assertNull($missing->items->first()?->hsn_sac);
    }

    public function test_pos_internal_receipt_is_not_listed_as_a_statutory_invoice(): void
    {
        Carbon::setTestNow('2026-09-20 16:00:00');
        $branch = InventoryBranch::query()->create(['code' => 'HQ', 'name' => 'HQ', 'is_active' => true]);
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS-CA',
            'name' => 'Scanner',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $branch, ['SN-CA-1'], $this->actor);
        $sale = app(PosSaleService::class)->completeSale(
            branch: $branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000000099'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['SN-CA-1']]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );

        $this->assertMatchesRegularExpression('/^INV-HQ-\d{4}-\d{5}$/', (string) $sale->invoice_number);

        $register = $this->actingAs($this->accountant)
            ->get(route('finance.invoices.export', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString((string) $sale->invoice_number, $register);
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_unknown_export_is_not_found_and_post_is_not_allowed(): void
    {
        $this->actingAs($this->accountant)
            ->get(route('finance.reports.export', ['report' => 'gstr1']))
            ->assertNotFound();

        $this->actingAs($this->accountant)
            ->post(route('finance.reports.index'))
            ->assertStatus(405);
    }

    private function enableTestSeries(): void
    {
        config([
            'statutory_invoices.series_code' => 'TEST',
            'statutory_invoices.number_format' => '{series}-{seq:5}',
            'statutory_invoices.document_type' => 'tax_invoice',
            'statutory_invoices.gstin_scope' => '',
            'statutory_invoices.financial_year' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'channel_ingest.auto_issue_invoice' => false,
            'channel_ingest.cutover_approved' => false,
        ]);
    }

    private function request(
        string $sourceId,
        StatutoryInvoiceChannel $channel = StatutoryInvoiceChannel::DeskPos,
        ?string $sourceOrderId = null,
        ?string $paymentMethod = null,
        ?string $paymentReference = null,
    ): StatutoryInvoiceMintRequest {
        return new StatutoryInvoiceMintRequest(
            channel: $channel,
            sourceType: StatutoryInvoiceSourceType::External,
            sourceId: $sourceId,
            sourceOrderId: $sourceOrderId,
            buyerName: 'CA Customer',
            buyerGstin: null,
            paymentMethod: $paymentMethod,
            paymentReference: $paymentReference,
            lines: [
                new StatutoryInvoiceLineDraft(
                    description: 'Test line',
                    qty: 1,
                    unitPrice: 100,
                    gstPercentage: 18,
                    taxTotal: 18,
                    lineTotal: 118,
                    taxableValue: 100,
                    hsnSac: '84716050',
                ),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ingestChannelOrder(array $payload): void
    {
        app(ChannelIngestService::class)->ingest(
            $payload,
            StatutoryInvoiceChannel::RdServiceIn,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function eligibleChannelPayload(string $sourceId): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'payment_method' => 'UPI',
            'currency' => 'INR',
            'customer' => [
                'name' => 'Walk-in',
                'phone' => '9000000001',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'RADium Desk',
            'place_of_supply_state' => 'Delhi',
            'lines' => [
                [
                    'description' => 'RD Service',
                    'sku' => 'RD-SVC',
                    'qty' => 1,
                    'unit_price' => 100,
                    'hsn_sac' => '998313',
                    'gst_percentage' => 18,
                    'taxable_value' => 100,
                    'tax_total' => 18,
                    'line_total' => 118,
                ],
            ],
        ];
    }
}
