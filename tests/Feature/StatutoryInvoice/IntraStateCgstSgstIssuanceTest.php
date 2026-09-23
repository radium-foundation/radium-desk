<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\User;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceStoredGstGuard;
use App\Services\StatutoryInvoice\StatutoryDocumentService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IntraStateCgstSgstIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private StatutoryInvoiceService $invoices;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-18 18:48:07');

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
            'statutory_invoices.invoice_scope_starts_at' => '2026-09-01 00:00:00',
            'channel_ingest.auto_issue_invoice' => false,
        ]);

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_odd_paise_single_line_mints_equal_cgst_sgst_and_passes_stored_guard(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD3512512', 534.75, 96.25, 631.0, buyerGstin: '27AAAAA0000A1Z5'),
            $this->actor,
        );

        $this->assertSame(48.13, (float) $invoice->cgst);
        $this->assertSame(48.13, (float) $invoice->sgst);
        $this->assertSame(96.25, (float) $invoice->tax_total);
        $this->assertSame([], EInvoiceStoredGstGuard::missingReasons($invoice));
    }

    public function test_multi_line_rd3300_shape_mints_equal_header_cgst_sgst(): void
    {
        $order = $this->commerceOrder('RD3300', 0, 0, 597.0, buyerGstin: '27AAAAA0000A1Z5');
        $order->items()->delete();
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 421.19,
            'gst_percentage' => 18,
            'taxable_value' => 421.19,
            'tax_total' => 75.81,
            'line_total' => 497.0,
        ]);
        $order->items()->create([
            'line_no' => 2,
            'description' => 'AMC : 1 Year Unlimited',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 84.75,
            'gst_percentage' => 18,
            'taxable_value' => 84.75,
            'tax_total' => 15.25,
            'line_total' => 100.0,
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame(45.54, (float) $invoice->cgst);
        $this->assertSame(45.54, (float) $invoice->sgst);
        $this->assertSame(91.06, (float) $invoice->tax_total);
        $this->assertSame(37.91, (float) $invoice->items[0]->cgst);
        $this->assertSame(37.91, (float) $invoice->items[0]->sgst);
        $this->assertSame(7.63, (float) $invoice->items[1]->cgst);
        $this->assertSame(7.63, (float) $invoice->items[1]->sgst);
        $this->assertSame([], EInvoiceStoredGstGuard::missingReasons($invoice));
    }

    public function test_irp_mapper_reads_stored_equal_components_without_recalculation(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD3512769', 534.75, 96.25, 631.0, buyerGstin: '27AAAAA0000A1Z5'),
            $this->actor,
        );

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);
        $item = $payload->items[0];

        $this->assertSame(48.13, (float) $item['cgst']);
        $this->assertSame(48.13, (float) $item['sgst']);
        $this->assertSame(48.13, (float) $payload->values['cgst']);
        $this->assertSame(48.13, (float) $payload->values['sgst']);
    }

    public function test_pdf_regeneration_preserves_stored_financials(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder('RD8376', 534.75, 96.25, 631.0),
            $this->actor,
        );
        $before = $invoice->only(['tax_total', 'cgst', 'sgst', 'igst', 'invoice_value']);

        app(StatutoryDocumentService::class)->regeneratePresentation($invoice->fresh(['items', 'eInvoiceRecord']));
        $after = $invoice->fresh();

        foreach ($before as $key => $value) {
            $this->assertSame((string) $value, (string) $after->{$key}, $key);
        }
    }

    public function test_inter_state_invoice_remains_igst_only(): void
    {
        $invoice = $this->invoices->issueFromCommerceOrder(
            $this->commerceOrder(
                'RD5787',
                422.88,
                76.12,
                499.0,
                placeOfSupply: 'Karnataka',
            ),
            $this->actor,
        );

        $this->assertSame(0.0, (float) $invoice->cgst);
        $this->assertSame(0.0, (float) $invoice->sgst);
        $this->assertSame(76.12, (float) $invoice->igst);
        $this->assertSame([], EInvoiceStoredGstGuard::missingReasons($invoice));
    }

    private function commerceOrder(
        string $sourceId,
        float $taxableValue,
        float $taxTotal,
        float $orderValue,
        ?string $buyerGstin = null,
        string $placeOfSupply = 'Maharashtra',
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
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Customer',
            'billing_state' => $placeOfSupply,
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => $placeOfSupply,
            'buyer_gstin' => $buyerGstin,
            'taxable_value' => $taxableValue,
            'tax_total' => $taxTotal,
            'order_value' => $orderValue,
            'ordered_at' => now(),
            'received_at' => now(),
        ]);

        if ($taxableValue > 0 || $taxTotal > 0) {
            $order->items()->create([
                'line_no' => 1,
                'description' => 'Information technology (IT) consulting & support services',
                'hsn_sac' => '998313',
                'qty' => 1,
                'unit_price' => $taxableValue,
                'gst_percentage' => 18,
                'taxable_value' => $taxableValue,
                'tax_total' => $taxTotal,
                'line_total' => $orderValue,
            ]);
        }

        return $order->fresh(['items']);
    }
}
