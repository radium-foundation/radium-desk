<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportPaymentChannelResolver;
use App\Services\StatutoryInvoice\Ast300L1ProductIdentity;
use App\Services\StatutoryInvoice\RdServiceInAst300CommerceSnapshotService;
use App\Services\StatutoryInvoice\RdServiceInAst300ServiceLineDescriptor;
use App\Services\StatutoryInvoice\StatutoryInvoiceCommerceBillableLines;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class RdServiceInAst300CommerceSnapshotTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private RdServiceInAst300CommerceSnapshotService $snapshots;

    private StatutoryMintEligibility $eligibility;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-26 12:00:00');

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
            'channel_ingest.auto_issue_invoice' => false,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->snapshots = app(RdServiceInAst300CommerceSnapshotService::class);
        $this->eligibility = app(StatutoryMintEligibility::class);
        $this->actor = User::factory()->create(['is_active' => true, 'email' => 'superadmin@radium.local']);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ast300_499_one_year_repair_and_mint(): void
    {
        [$support, $commerce] = $this->ast300Pair(
            'RD3514001',
            paymentAmount: 499.0,
            durationLabel: '1 Year',
        );

        $repaired = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);

        $this->assertBillableAst300Amounts($repaired, gross: 499.0, taxable: 422.88, tax: 76.12);
        $this->assertTrue($this->eligibility->evaluateOrder($repaired)->eligible);

        $invoice = $this->invoices->issueFromSupportOrder($support, $this->actor);

        $this->assertSame(499.0, (float) $invoice->invoice_value);
        $this->assertSame(422.88, (float) $invoice->taxable_value);
        $this->assertSame(76.12, (float) $invoice->tax_total);
        $this->assertGreaterThan(0, (float) $invoice->items[0]->line_total);
    }

    public function test_ast300_677_two_year_repair_and_mint(): void
    {
        [$support, $commerce] = $this->ast300Pair(
            'RD3514002',
            paymentAmount: 677.0,
            durationLabel: '2 Years',
        );

        $repaired = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);

        $this->assertBillableAst300Amounts($repaired, gross: 677.0, taxable: 573.73, tax: 103.27);
        $this->assertTrue($this->eligibility->evaluateOrder($repaired)->eligible);

        $invoice = $this->invoices->issueFromSupportOrder($support, $this->actor);

        $this->assertSame(677.0, (float) $invoice->invoice_value);
        $this->assertSame(573.73, (float) $invoice->taxable_value);
        $this->assertSame(103.27, (float) $invoice->tax_total);
    }

    public function test_inter_state_ast300_uses_igst(): void
    {
        [$support, $commerce] = $this->ast300Pair(
            'RD3514003',
            paymentAmount: 499.0,
            placeOfSupply: 'Uttar Pradesh',
        );

        $repaired = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);
        $billable = $repaired->items->first(
            fn ($item) => ! str_contains(strtolower((string) $item->description), 'rd technical support'),
        );

        $this->assertSame(76.12, (float) $billable->igst);
        $this->assertSame(0.0, (float) $billable->cgst);
        $this->assertSame(0.0, (float) $billable->sgst);

        $invoice = $this->invoices->issueFromSupportOrder($support, $this->actor);
        $this->assertSame(76.12, (float) $invoice->igst);
        $this->assertSame(0.0, (float) $invoice->cgst);
        $this->assertSame(0.0, (float) $invoice->sgst);
    }

    public function test_delhi_intra_state_ast300_uses_cgst_and_sgst(): void
    {
        [$support, $commerce] = $this->ast300Pair(
            'RD3514004',
            paymentAmount: 499.0,
            placeOfSupply: 'Delhi',
            sellerGstin: $this->configuredSellerGstin('delhi'),
        );

        $repaired = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);
        $billable = $repaired->items->first(
            fn ($item) => ! str_contains(strtolower((string) $item->description), 'rd technical support'),
        );

        $this->assertSame(0.0, (float) $billable->igst);
        $this->assertSame(38.06, (float) $billable->cgst);
        $this->assertSame(38.06, (float) $billable->sgst);

        $invoice = $this->invoices->issueFromSupportOrder($support, $this->actor);
        $this->assertSame(38.06, (float) $invoice->cgst);
        $this->assertSame(38.06, (float) $invoice->sgst);
        $this->assertSame(0.0, (float) $invoice->igst);
    }

    public function test_included_support_line_remains_zero_companion(): void
    {
        [$support, $commerce] = $this->ast300Pair('RD3514005', paymentAmount: 499.0);

        $repaired = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);

        $this->assertCount(2, $repaired->items);
        $included = $repaired->items->firstWhere(
            'description',
            RdServiceInAst300ServiceLineDescriptor::INCLUDED_SUPPORT,
        );
        $this->assertNotNull($included);
        $this->assertSame(0.0, (float) $included->line_total);
        $this->assertSame(2, (int) $included->line_no);

        $billableCount = app(StatutoryInvoiceCommerceBillableLines::class)->forOrder($repaired)->count();
        $this->assertSame(1, $billableCount);
    }

    public function test_repeated_repair_is_idempotent(): void
    {
        [$support, $commerce] = $this->ast300Pair('RD3514006', paymentAmount: 499.0);

        $first = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);
        $second = $this->snapshots->repairSupportOnlyCommerceIfNeeded($first->fresh(['items']), $support);
        $invoice = $this->invoices->issueFromSupportOrder($support, $this->actor);
        $third = $this->invoices->issueFromSupportOrder($support->fresh(), $this->actor);

        $this->assertSame(2, $second->items()->count());
        $this->assertSame($invoice->id, $third->id);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_missing_payment_does_not_invent_invoice_value(): void
    {
        [$support, $commerce] = $this->ast300Pair(
            'RD3514007',
            paymentAmount: 0.0,
            withPaymentReference: false,
        );

        $repaired = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);

        $this->assertSame(0.0, (float) $repaired->order_value);
        $this->assertFalse($this->eligibility->evaluateOrder($repaired)->eligible);

        try {
            $this->invoices->issueFromSupportOrder($support, $this->actor);
            $this->fail('Expected support-only AST300 without payment to fail closed.');
        } catch (ValidationException $exception) {
            $blocked = array_merge(
                $exception->errors()['eligibility'] ?? [],
                $exception->errors()['commerce_order'] ?? [],
            );
            $this->assertContains(StatutoryInvoiceCommerceBillableLines::NO_BILLABLE_LINES, $blocked);
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_payment_without_establishable_transaction_fails_closed(): void
    {
        [$support, $commerce] = $this->ast300Pair(
            'RD3514008',
            paymentAmount: 499.0,
            withPaymentReference: false,
        );

        $repaired = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);

        $this->assertSame(0.0, (float) $repaired->order_value);
        $this->assertFalse(app(StatutoryInvoiceCommerceBillableLines::class)->hasAny($repaired));
    }

    public function test_historical_corrected_invoices_are_not_repaired(): void
    {
        $historicalIds = [376, 556, 581, 672, 1196, 1443, 2069, 2510, 2836];

        foreach ($historicalIds as $index => $statutoryId) {
            $sourceId = 'RD3515'.$index;
            $invoice = $this->makeTaxInvoice([
                'id' => $statutoryId,
                'invoice_number' => 'INV-HIST-'.$index,
                'channel' => StatutoryInvoiceChannel::RdServiceIn,
                'source_id' => $sourceId,
                'idempotency_key' => 'statutory:rdservice_in:commerce_order:'.$sourceId,
                'invoice_value' => '499.00',
                'taxable_value' => '422.88',
                'tax_total' => '76.12',
                'issued_at' => '2026-09-10 10:00:00',
            ]);

            $support = $this->ast300SupportOrder($sourceId, 499.0);
            $commerce = $this->supportOnlyCommerce($sourceId, statutoryInvoiceId: $invoice->id);

            $repaired = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);

            $this->assertSame(0.0, (float) $repaired->order_value);
            $this->assertCount(1, $repaired->items);
            $this->assertSame(
                RdServiceInAst300ServiceLineDescriptor::INCLUDED_SUPPORT,
                $repaired->items[0]->description,
            );
        }
    }

    public function test_normal_rdservice_in_paid_order_regression(): void
    {
        $order = $this->paidServiceCommerce('RD3514010');

        $this->assertTrue($this->eligibility->evaluateOrder($order)->eligible);
        $invoice = $this->invoices->issueFromCommerceOrder($order, $this->actor);

        $this->assertSame(499.0, (float) $invoice->invoice_value);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_non_ast300_support_only_order_is_not_repaired(): void
    {
        $support = Order::query()->create([
            'order_id' => 'RD3514011',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'payment_amount' => 499.0,
            'cashfree_payment_id' => 'cf_mfs',
            'status' => 'active',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $commerce = $this->supportOnlyCommerce('RD3514011');

        $repaired = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);

        $this->assertSame(0.0, (float) $repaired->order_value);
        $this->assertFalse($this->eligibility->evaluateOrder($repaired)->eligible);
    }

    public function test_ast300_gstin_state_mismatch_issues_b2c_regression(): void
    {
        [$support, $commerce] = $this->ast300Pair(
            'RD3514012',
            paymentAmount: 499.0,
            buyerGstin: '27AAAAA0000A1Z5',
            billingState: 'Karnataka',
            placeOfSupply: 'Karnataka',
        );

        $repaired = $this->snapshots->repairSupportOnlyCommerceIfNeeded($commerce, $support);
        $invoice = $this->invoices->issueFromSupportOrder($support, $this->actor);

        $this->assertTrue($this->eligibility->evaluateOrder($repaired)->eligible);
        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame(499.0, (float) $invoice->invoice_value);
    }

    public function test_ca_monthly_export_shows_non_zero_ast300_invoice_and_cf_channel(): void
    {
        [$support] = $this->ast300Pair('RD3514013', paymentAmount: 499.0);
        $invoice = $this->invoices->issueFromSupportOrder($support, $this->actor);

        $rows = app(CaMonthlyStatutoryLineReadModel::class)->exportRows(new Request([
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
        ]));

        $row = collect($rows)->first(fn (array $line): bool => ($line[2] ?? null) === $invoice->invoice_number);
        $this->assertNotNull($row);
        $this->assertSame('499.00', $row[18]);
        $this->assertNotSame('0.00', $row[18]);
        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
    }

    public function test_ast300_product_identity_matches_support_and_metadata(): void
    {
        $identity = app(Ast300L1ProductIdentity::class);
        $support = $this->ast300SupportOrder('RD3514014', 499.0);
        $commerce = $this->supportOnlyCommerce('RD3514014', metadata: ['product_name' => 'AST300 L1']);

        $this->assertTrue($identity->matches($support, $commerce));
        $this->assertTrue($identity->matchesSupportOrder($support));
        $this->assertTrue($identity->matchesCommerceMetadata($commerce));
    }

    /**
     * @return array{0: Order, 1: CommerceOrder}
     */
    private function ast300Pair(
        string $sourceId,
        float $paymentAmount = 499.0,
        string $durationLabel = '1 Year',
        ?string $buyerGstin = null,
        ?string $billingState = 'Uttar Pradesh',
        string $placeOfSupply = 'Uttar Pradesh',
        ?string $sellerGstin = null,
        bool $withPaymentReference = true,
    ): array {
        $support = $this->ast300SupportOrder(
            $sourceId,
            $paymentAmount,
            withPaymentReference: $withPaymentReference,
        );
        $commerce = $this->supportOnlyCommerce(
            $sourceId,
            metadata: [
                'product_name' => Ast300L1ProductIdentity::PRODUCT_NAME,
                'rd_service_name' => $durationLabel,
                'serial_no' => 'AST3001234',
            ],
            buyerGstin: $buyerGstin,
            billingState: $billingState,
            placeOfSupply: $placeOfSupply,
            sellerGstin: $sellerGstin ?? $this->configuredSellerGstin('delhi'),
            supportOrderId: $support->id,
        );

        return [$support, $commerce];
    }

    private function ast300SupportOrder(
        string $orderId,
        float $paymentAmount,
        bool $withPaymentReference = true,
    ): Order {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'AST3001234',
            'product_name' => Ast300L1ProductIdentity::PRODUCT_NAME,
            'device_model' => Ast300L1ProductIdentity::PRODUCT_NAME,
            'payment_amount' => $paymentAmount,
            'cashfree_payment_id' => $withPaymentReference ? 'cf_'.$orderId : null,
            'transaction_id' => null,
            'status' => 'active',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function supportOnlyCommerce(
        string $sourceId,
        array $metadata = [],
        ?int $statutoryInvoiceId = null,
        ?string $buyerGstin = null,
        ?string $billingState = 'Uttar Pradesh',
        string $placeOfSupply = 'Uttar Pradesh',
        ?string $sellerGstin = null,
        ?int $supportOrderId = null,
    ): CommerceOrder {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => $statutoryInvoiceId === null,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'buyer_gstin' => $buyerGstin,
            'billing_state' => $billingState,
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => $placeOfSupply,
            'seller_gstin' => $sellerGstin ?? $this->configuredSellerGstin('delhi'),
            'taxable_value' => 0,
            'tax_total' => 0,
            'order_value' => 0,
            'metadata' => $metadata,
            'support_order_id' => $supportOrderId,
            'statutory_invoice_id' => $statutoryInvoiceId,
            'ordered_at' => now(),
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => RdServiceInAst300ServiceLineDescriptor::INCLUDED_SUPPORT,
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 0,
            'gst_percentage' => 18,
            'taxable_value' => 0,
            'tax_total' => 0,
            'line_total' => 0,
        ]);

        return $order->fresh(['items']);
    }

    private function paidServiceCommerce(string $sourceId): CommerceOrder
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'billing_state' => 'Uttar Pradesh',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Uttar Pradesh',
            'seller_gstin' => $this->configuredSellerGstin('delhi'),
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'order_value' => 499,
            'ordered_at' => now(),
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 422.88,
            'gst_percentage' => 18,
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'line_total' => 499,
        ]);

        return $order->fresh(['items']);
    }

    private function assertBillableAst300Amounts(
        CommerceOrder $order,
        float $gross,
        float $taxable,
        float $tax,
    ): void {
        $this->assertSame($taxable, (float) $order->taxable_value);
        $this->assertSame($tax, (float) $order->tax_total);
        $this->assertSame($gross, (float) $order->order_value);
        $this->assertTrue($order->invoice_eligible);

        $billable = $order->items->first(
            fn ($item) => ! str_contains(strtolower((string) $item->description), 'rd technical support'),
        );
        $this->assertNotNull($billable);
        $this->assertSame($taxable, (float) $billable->taxable_value);
        $this->assertSame($tax, (float) $billable->tax_total);
        $this->assertSame($gross, (float) $billable->line_total);
        $this->assertStringContainsString('AST3001234', (string) $billable->description);
    }
}
