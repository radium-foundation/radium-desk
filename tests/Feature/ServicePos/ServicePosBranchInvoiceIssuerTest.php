<?php

namespace Tests\Feature\ServicePos;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutorySupplyKind;
use App\Models\CommerceOrder;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\ServiceItem;
use App\Models\ServiceOrder;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ServicePos\ServiceQuoteService;
use App\Services\StatutoryInvoice\StatutoryBillingIssuer;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServicePosBranchInvoiceIssuerTest extends TestCase
{
    use RefreshDatabase;

    private ServiceQuoteService $quotes;

    private StatutoryInvoiceService $invoices;

    private StatutoryBillingIssuer $issuer;

    private User $actor;

    private InventoryCustomer $customer;

    private ServiceItem $serviceLine;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 17:58:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->quotes = app(ServiceQuoteService::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->issuer = app(StatutoryBillingIssuer::class);
        $this->actor = User::factory()->create(['is_active' => true]);

        $this->customer = InventoryCustomer::query()->create([
            'name' => 'Branch Issuer Customer',
            'phone' => '9000002767',
            'email' => 'branch-issuer@example.test',
        ]);

        $this->serviceLine = ServiceItem::query()->where('code', 'DEV-RD-1Y')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_delhi_branch_with_maharashtra_buyer_gstin_bills_from_delhi(): void
    {
        $delhi = $this->branch('DELHI-RETAIL', 'Delhi Retail', '07AAICP1128M1Z9');
        $order = $this->orderFromQuote(
            branch: $delhi,
            billingState: 'Maharashtra',
            placeOfSupplyState: 'Maharashtra',
            buyerGstin: '27AADCU1916H1ZE',
        );

        $invoice = $this->invoices->issueFromServiceOrder($order->fresh(['lines', 'branch']), $this->actor);

        $this->assertSame($delhi->id, (int) $invoice->branch_id);
        $this->assertSame($this->configuredSellerGstin('delhi'), $invoice->seller_gstin);
        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertSame('Maharashtra', $invoice->place_of_supply_state);
        $this->assertSame('27AADCU1916H1ZE', $invoice->buyer_gstin);
        $this->assertSame(0.0, (float) $invoice->cgst);
        $this->assertSame(0.0, (float) $invoice->sgst);
        $this->assertGreaterThan(0.0, (float) $invoice->igst);
    }

    public function test_mumbai_branch_with_delhi_buyer_gstin_bills_from_mumbai(): void
    {
        $mumbai = $this->branch('MUMBAI', 'Mumbai', '27AAICP1128M1Z7');
        $order = $this->orderFromQuote(
            branch: $mumbai,
            billingState: 'Delhi',
            placeOfSupplyState: 'Delhi',
            buyerGstin: '07AAAAA0000A1Z5',
        );

        $invoice = $this->invoices->issueFromServiceOrder($order->fresh(['lines', 'branch']), $this->actor);

        $this->assertSame($mumbai->id, (int) $invoice->branch_id);
        $this->assertSame($this->configuredSellerGstin('mumbai'), $invoice->seller_gstin);
        $this->assertSame('INV-27671', $invoice->invoice_number);
        $this->assertSame('07AAAAA0000A1Z5', $invoice->buyer_gstin);
    }

    public function test_service_pos_issuer_follows_branch_not_commerce_service_rules(): void
    {
        $this->assertSame(
            StatutoryLocationSeries::DELHI,
            $this->issuer->requireForServicePosBranch('DELHI-RETAIL'),
        );
        $this->assertSame(
            StatutoryLocationSeries::MUMBAI,
            $this->issuer->requireForServicePosBranch('MUMBAI'),
        );
        $this->assertSame(
            StatutoryLocationSeries::MUMBAI,
            $this->issuer->require(
                StatutorySupplyKind::Service,
                'DELHI-RETAIL',
                '27AAAAA0000A1Z5',
                'Maharashtra',
            ),
        );
    }

    public function test_unmapped_service_pos_branch_fails_closed_without_mumbai_fallback(): void
    {
        $unknown = $this->branch('REMOTE-1', 'Remote', null);
        $order = $this->orderFromQuote(
            branch: $unknown,
            billingState: 'Maharashtra',
            placeOfSupplyState: 'Maharashtra',
            buyerGstin: '27AAAAA0000A1Z5',
        );

        $this->expectException(ValidationException::class);
        $this->invoices->issueFromServiceOrder($order->fresh(['lines', 'branch']), $this->actor);
    }

    public function test_repeated_issue_from_service_order_is_idempotent(): void
    {
        $delhi = $this->branch('DELHI-RETAIL', 'Delhi Retail', '07AAICP1128M1Z9');
        $order = $this->orderFromQuote(
            branch: $delhi,
            billingState: 'Delhi',
            placeOfSupplyState: 'Delhi',
            buyerGstin: null,
        );

        $first = $this->invoices->issueFromServiceOrder($order->fresh(['lines', 'branch']), $this->actor);
        $second = $this->invoices->issueFromServiceOrder($order->fresh(['lines', 'branch']), $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertSame(StatutoryInvoiceChannel::DeskService, $first->channel);
        $this->assertSame(StatutoryInvoiceSourceType::ServiceOrder->value, $first->source_type);
    }

    public function test_online_commerce_maharashtra_b2b_still_uses_mumbai_issuer_despite_delhi_branch_code(): void
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-SVC-MH-COMM',
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => 'commerce_order',
            'source_id' => 'SVC-MH-COMM',
            'source_order_id' => 'SVC-MH-COMM',
            'idempotency_key' => 'statutory:rdservice_net:commerce_order:SVC-MH-COMM',
            'payload_hash' => hash('sha256', 'SVC-MH-COMM'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Commerce Customer',
            'buyer_gstin' => '27AAAAA0000A1Z5',
            'billing_state' => 'Maharashtra',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Maharashtra',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'ordered_at' => '2026-09-01 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Line',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 100,
            'gst_percentage' => 18,
            'taxable_value' => 100,
            'tax_total' => 18,
            'line_total' => 118,
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame('INV-27671', $invoice->invoice_number);
        $this->assertSame($this->configuredSellerGstin('mumbai'), $invoice->seller_gstin);
        $this->assertSame('Maharashtra', $invoice->place_of_supply_state);
    }

    public function test_delhi_branch_intra_state_service_pos_uses_cgst_sgst(): void
    {
        $delhi = $this->branch('DELHI-RETAIL', 'Delhi Retail', '07AAICP1128M1Z9');
        $order = $this->orderFromQuote(
            branch: $delhi,
            billingState: 'Delhi',
            placeOfSupplyState: 'Delhi',
            buyerGstin: '07AAAAA0000A1Z5',
        );

        $invoice = $this->invoices->issueFromServiceOrder($order->fresh(['lines', 'branch']), $this->actor);

        $this->assertSame($this->configuredSellerGstin('delhi'), $invoice->seller_gstin);
        $this->assertGreaterThan(0.0, (float) $invoice->cgst);
        $this->assertGreaterThan(0.0, (float) $invoice->sgst);
        $this->assertSame(0.0, (float) $invoice->igst);
    }

    private function branch(string $code, string $name, ?string $gstin): InventoryBranch
    {
        return InventoryBranch::query()->create([
            'code' => $code,
            'name' => $name,
            'gstin' => $gstin,
            'is_active' => true,
        ]);
    }

    private function orderFromQuote(
        InventoryBranch $branch,
        string $billingState,
        string $placeOfSupplyState,
        ?string $buyerGstin,
    ): ServiceOrder {
        $quote = $this->quotes->createQuote(
            $this->customer,
            $branch,
            [['service_item_id' => $this->serviceLine->id, 'qty' => 1]],
            $this->actor,
            billingState: $billingState,
            placeOfSupplyState: $placeOfSupplyState,
            buyerGstin: $buyerGstin,
        );

        return $this->quotes->convertToOrder($quote, $this->actor);
    }
}
