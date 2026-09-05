<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutorySupplyKind;
use App\Models\CommerceOrder;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\StatutoryBillingIssuer;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StatutoryBillingIssuerTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private StatutoryBillingIssuer $issuer;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->invoices = app(StatutoryInvoiceService::class);
        $this->issuer = app(StatutoryBillingIssuer::class);
        $this->actor = User::factory()->create(['is_active' => true]);

        config([
            'statutory_invoices.location_series.enabled' => true,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.gstin_scope' => '07AAICP1128M1Z9',
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
    }

    public function test_product_issuer_follows_branch_not_customer_state(): void
    {
        $this->assertSame(
            StatutoryLocationSeries::DELHI,
            $this->issuer->require(StatutorySupplyKind::Product, 'DELHI-RETAIL', '27AAAAA0000A1Z5', 'Maharashtra'),
        );
        $this->assertSame(
            StatutoryLocationSeries::MUMBAI,
            $this->issuer->require(StatutorySupplyKind::Product, 'MUMBAI', '07AAAAA0000A1Z5', 'Delhi'),
        );
    }

    public function test_service_b2b_maharashtra_bills_from_mumbai(): void
    {
        $this->assertSame(
            StatutoryLocationSeries::MUMBAI,
            $this->issuer->require(StatutorySupplyKind::Service, 'DELHI-RETAIL', '27AAAAA0000A1Z5', 'Maharashtra'),
        );
    }

    public function test_service_b2b_non_maharashtra_bills_from_delhi(): void
    {
        foreach (['07AAAAA0000A1Z5' => 'Delhi', '29AAAAA0000A1Z5' => 'Karnataka', '24AAAAA0000A1Z5' => 'Gujarat'] as $gstin => $state) {
            $this->assertSame(
                StatutoryLocationSeries::DELHI,
                $this->issuer->require(StatutorySupplyKind::Service, 'MUMBAI', $gstin, $state),
                $state,
            );
        }
    }

    public function test_service_b2c_always_bills_from_delhi(): void
    {
        foreach (['Maharashtra', 'Delhi', 'Karnataka'] as $state) {
            $this->assertSame(
                StatutoryLocationSeries::DELHI,
                $this->issuer->require(StatutorySupplyKind::Service, 'MUMBAI', null, $state),
                $state,
            );
        }
    }

    public function test_service_issuer_does_not_use_place_of_supply(): void
    {
        $location = $this->issuer->require(StatutorySupplyKind::Service, null, '27AAAAA0000A1Z5', 'Maharashtra');
        $this->assertSame(StatutoryLocationSeries::MUMBAI, $location);
    }

    public function test_missing_product_branch_and_conflicting_b2b_state_fail_closed(): void
    {
        $this->expectException(ValidationException::class);
        $this->issuer->require(StatutorySupplyKind::Product, 'HQ', null, null);
    }

    public function test_conflicting_b2b_customer_state_and_gstin_fail_closed(): void
    {
        $this->expectException(ValidationException::class);
        $this->issuer->require(StatutorySupplyKind::Service, null, '27AAAAA0000A1Z5', 'Karnataka');
    }

    public function test_unknown_gstin_state_code_fails_closed(): void
    {
        $this->expectException(ValidationException::class);
        $this->issuer->require(StatutorySupplyKind::Service, null, '00AAAAA0000A1Z5', null);
    }

    public function test_commerce_b2b_maharashtra_service_keeps_place_of_supply(): void
    {
        $order = $this->commerceOrder(
            sourceId: 'SVC-MH-B2B',
            hsn: '998313',
            branchCode: 'DELHI-RETAIL',
            placeOfSupply: 'Maharashtra',
            buyerGstin: '27AAAAA0000A1Z5',
        );

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);

        $this->assertSame('INV-27671', $invoice->invoice_number);
        $this->assertSame('Maharashtra', $invoice->place_of_supply_state);
        $this->assertSame('27AAAAA0000A1Z5', $invoice->buyer_gstin);
        $this->assertSame('Maharashtra', $order->fresh()->place_of_supply_state);
        $this->assertSame('DELHI-RETAIL', $order->fresh()->branch_code);
    }

    public function test_commerce_b2c_maharashtra_service_bills_delhi_and_keeps_place_of_supply(): void
    {
        $order = $this->commerceOrder(
            sourceId: 'SVC-MH-B2C',
            hsn: '998313',
            branchCode: 'MUMBAI',
            placeOfSupply: 'Maharashtra',
            buyerGstin: null,
        );

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);

        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertSame('Maharashtra', $invoice->place_of_supply_state);
        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame('MUMBAI', $order->fresh()->branch_code);
    }

    public function test_commerce_product_uses_mumbai_branch_even_for_delhi_customer(): void
    {
        $order = $this->commerceOrder(
            sourceId: 'PRD-MUM',
            hsn: '84716050',
            branchCode: 'MUMBAI',
            placeOfSupply: 'Delhi',
            buyerGstin: '07AAAAA0000A1Z5',
        );

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);

        $this->assertSame('INV-27671', $invoice->invoice_number);
        $this->assertSame('Delhi', $invoice->place_of_supply_state);
        $this->assertSame('07AAAAA0000A1Z5', $invoice->buyer_gstin);
    }

    public function test_pos_product_on_delhi_branch_stays_delhi_for_maharashtra_customer(): void
    {
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-ISS',
            'name' => 'Mantra MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $branch, ['ISS-SERIAL-1'], $this->actor);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $branch,
            customer: ['name' => 'Walk-in', 'phone' => '9000000077'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['ISS-SERIAL-1'],
            ]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: [
                'buyer_gstin' => '27AAAAA0000A1Z5',
                'place_of_supply_state' => 'Maharashtra',
            ],
        );
        $invoice = $this->invoices->issueFromPosSale($sale, $this->actor);

        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertSame('Maharashtra', $invoice->place_of_supply_state);
        $this->assertSame('27AAAAA0000A1Z5', $invoice->buyer_gstin);
        $this->assertMatchesRegularExpression('/^INV-DELHI-RETAIL-\d{4}-\d{5}$/', (string) $sale->invoice_number);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_pre_september_commerce_order_cannot_receive_a_statutory_number(): void
    {
        $order = $this->commerceOrder(
            sourceId: 'PRE-SEPT',
            hsn: '998313',
            branchCode: 'DELHI-RETAIL',
            placeOfSupply: 'Delhi',
            buyerGstin: null,
            orderedAt: '2026-08-31 23:59:59',
        );

        try {
            $this->invoices->issueFromCommerceOrder($order, $this->actor);
            $this->fail('Expected pre-2026-09-01 commerce order to refuse mint.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('2026-09-01', implode(' ', $exception->errors()['eligibility'] ?? []));
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_first_eligible_september_commerce_service_issues_inv_07671(): void
    {
        $order = $this->commerceOrder(
            sourceId: 'SEPT-1',
            hsn: '998314',
            branchCode: 'DELHI-RETAIL',
            placeOfSupply: 'Delhi',
            buyerGstin: null,
            orderedAt: '2026-09-01 00:00:00',
        );

        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);

        $this->assertSame('INV-07671', $invoice->invoice_number);
        $this->assertSame(1, $invoice->allocation?->seq_int);
    }

    public function test_mixed_product_and_service_lines_fail_closed(): void
    {
        $order = $this->commerceOrder(
            sourceId: 'MIXED',
            hsn: '998313',
            branchCode: 'DELHI-RETAIL',
            placeOfSupply: 'Delhi',
            buyerGstin: null,
        );
        $order->items()->create([
            'line_no' => 2,
            'description' => 'Device',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => 10,
            'gst_percentage' => 18,
            'taxable_value' => 10,
            'tax_total' => 1.8,
            'line_total' => 11.8,
        ]);

        $this->expectException(ValidationException::class);
        $this->invoices->issueFromCommerceOrder($order->fresh(), $this->actor);
    }

    private function commerceOrder(
        string $sourceId,
        string $hsn,
        ?string $branchCode,
        string $placeOfSupply,
        ?string $buyerGstin,
        string $orderedAt = '2026-09-01 10:00:00',
    ): CommerceOrder {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_net:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'buyer_gstin' => $buyerGstin,
            'branch_code' => $branchCode,
            'place_of_supply_state' => $placeOfSupply,
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'ordered_at' => $orderedAt,
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Line',
            'hsn_sac' => $hsn,
            'qty' => 1,
            'unit_price' => 100,
            'gst_percentage' => 18,
            'taxable_value' => 100,
            'tax_total' => 18,
            'line_total' => 118,
        ]);

        return $order->fresh(['items']);
    }
}
